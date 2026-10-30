<?php

declare(strict_types=1);

namespace Modules\Attendees\Services;

use Modules\Core\Config\Slam as SlamConfig;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Throwable;

/**
 * Two-step CSV import of attendees: preview (dry run, rows stored in
 * import_batch_rows) then commit (one transaction: all valid rows or nothing).
 * Importing never checks anyone in.
 */
class ImportService
{
    public const COLUMNS = ['name', 'mobile', 'email', 'type', 'badge_id'];

    public static function template(): string
    {
        return "name,mobile,email,type,badge_id\nAnu Thomas,9876543210,anu@example.com,user,\nഡോ. രാജൻ,+91 94470 12345,,expert,\n";
    }

    /**
     * Parse CSV text into header-keyed rows. Handles a UTF-8 BOM, Excel CRLF and
     * `,` or `;` delimiters (auto-detected from the header line).
     *
     * @return array{rows: list<array{line:int, cells: array<string,string>}>, missing: list<string>}
     */
    public function parse(string $content, int $maxRows): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new DomainException('encoding', 'The file is not UTF-8. In Excel use "CSV UTF-8 (Comma delimited)".');
        }
        $firstLine = strtok($content, "\r\n") ?: '';
        $delim     = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = fgetcsv($fh, null, $delim, '"', '');
        if ($header === false || $header === [null]) {
            throw new DomainException('empty', 'The file is empty.');
        }
        $header  = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        $missing = array_values(array_diff(['name', 'mobile'], $header));
        if ($missing !== []) {
            throw new DomainException('missing_columns', 'Missing required column(s): ' . implode(', ', $missing) . '.');
        }
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($fh, null, $delim, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            if (count($rows) >= $maxRows) {
                throw new DomainException('too_many_rows', "The file has more than {$maxRows} rows. Split it into smaller files.");
            }
            $row = [];
            foreach ($header as $i => $h) {
                if (in_array($h, self::COLUMNS, true)) {
                    $row[$h] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $rows[] = ['line' => $line, 'cells' => $row];
        }
        fclose($fh);

        return ['rows' => $rows, 'missing' => []];
    }

    /**
     * Validate one row. Returns [status, normalised values, error].
     *
     * @param array<string, string> $c
     * @return array{0: string, 1: array<string, ?string>, 2: ?string}
     */
    public function validateRow(array $c): array
    {
        $errs  = [];
        $name  = trim((string) preg_replace('/\s+/u', ' ', $c['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errs[] = 'name must be 2–120 characters';
        }
        $mobile = AttendeeService::normaliseMobile($c['mobile'] ?? '');
        if ($mobile === null) {
            $errs[] = 'mobile must be a valid 10-digit Indian number';
        }
        $email = strtolower(trim($c['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errs[] = 'invalid email';
        }
        $type = strtolower(trim($c['type'] ?? ''));
        // "expert" is the word used now; "judge" is still read as an expert (older files); blank or "user" = a judge from the audience.
        $type = match ($type) { '' , 'user', 'audience' => 'user', 'expert', 'judge' => 'judge', default => $type };
        if (! in_array($type, ['user', 'judge'], true)) {
            $errs[] = 'type must be user (audience judge) or expert';
        }
        $badge = BadgeIdService::normalise($c['badge_id'] ?? '');
        if ($badge !== '' && ! BadgeIdService::isValidFormat($badge)) {
            $errs[] = 'badge_id must be 2 letters (no I, O, Q, S, Z) + 5 digits';
        }
        $vals = ['name' => $name, 'mobile' => $mobile ?? ($c['mobile'] ?? ''), 'email' => $email === '' ? null : $email, 'user_type' => $type, 'badge_id' => $badge === '' ? null : $badge];

        return [$errs === [] ? 'new' : 'invalid', $vals, $errs === [] ? null : implode('; ', $errs)];
    }

    /**
     * Step 1: upload + validate. Nothing is written to attendees.
     *
     * @return array<string, mixed> batch summary
     */
    public function preview(int $slamId, string $path, string $filename, string $mode, Actor $actor): array
    {
        $cfg = config(SlamConfig::class);
        if (! in_array($mode, ['skip_existing', 'update_existing'], true)) {
            $mode = 'skip_existing';
        }
        if (! is_file($path) || filesize($path) === 0) {
            throw new DomainException('empty', 'The file is empty.');
        }
        if (filesize($path) > $cfg->importMaxBytes) {
            throw new DomainException('too_large', 'The file is larger than 2 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel'], true)) {
            throw new DomainException('bad_type', 'Only CSV files can be imported.');
        }
        service('slams')->find($slamId);
        $content = (string) file_get_contents($path);
        $sha     = hash('sha256', $content);
        $parsed  = $this->parse($content, $cfg->importMaxRows);

        $existingMobiles = [];
        foreach (Db::rows('SELECT mobile FROM attendees WHERE slam_id = ?', [$slamId]) as $r) {
            $existingMobiles[$r['mobile']] = true;
        }
        $seenMobiles = [];
        $seenBadges  = [];
        $out         = [];
        $counts      = ['new' => 0, 'duplicate' => 0, 'invalid' => 0];
        foreach ($parsed['rows'] as $r) {
            [$status, $v, $err] = $this->validateRow($r['cells']);
            if ($status === 'new') {
                if (isset($seenMobiles[$v['mobile']])) {
                    $status = 'invalid';
                    $err    = 'duplicate mobile in this file (first at line ' . $seenMobiles[$v['mobile']] . ')';
                } elseif ($v['badge_id'] !== null && (isset($seenBadges[$v['badge_id']]) || service('badgeIds')->exists($v['badge_id']))) {
                    $status = 'invalid';
                    $err    = 'badge_id is already in use';
                } elseif (isset($existingMobiles[$v['mobile']])) {
                    $status = 'duplicate';
                    $err    = $mode === 'skip_existing' ? 'already registered (will be skipped)' : 'already registered (name, email and type will be updated)';
                }
                $seenMobiles[$v['mobile']] ??= $r['line'];
                if ($v['badge_id'] !== null) {
                    $seenBadges[$v['badge_id']] = true;
                }
            }
            $counts[$status]++;
            $out[] = ['line_no' => $r['line'], 'status' => $status, 'error' => $err] + $v;
        }

        $alreadyCommitted = Db::value("SELECT id FROM import_batches WHERE slam_id = ? AND file_sha256 = ? AND status = 'committed' LIMIT 1", [$slamId, $sha]);

        $batchId = Db::tx(function ($db) use ($slamId, $filename, $sha, $actor, $mode, $out, $counts): int {
            $db->table('import_batches')->insert([
                'slam_id' => $slamId, 'filename' => mb_substr(basename($filename), 0, 255), 'file_sha256' => $sha,
                'uploaded_by' => (int) $actor->id, 'mode' => $mode, 'rows_total' => count($out),
                'rows_failed' => $counts['invalid'], 'status' => 'previewed', 'created_at' => Clock::db(),
            ]);
            $batchId = (int) $db->insertID();
            $now     = Clock::db();
            foreach (array_chunk($out, 500) as $chunk) {
                $db->table('import_batch_rows')->insertBatch(array_map(static fn ($r) => [
                    'batch_id' => $batchId, 'line_no' => $r['line_no'], 'status' => $r['status'],
                    'name' => mb_substr((string) $r['name'], 0, 255), 'mobile' => mb_substr((string) $r['mobile'], 0, 40),
                    'email' => $r['email'] !== null ? mb_substr($r['email'], 0, 255) : null, 'user_type' => $r['user_type'],
                    'badge_id' => $r['badge_id'], 'error' => $r['error'] !== null ? mb_substr($r['error'], 0, 500) : null, 'created_at' => $now,
                ], $chunk));
            }

            return $batchId;
        });

        service('activityLog')->log($actor, 'attendee.import.previewed', [
            'slam_id' => $slamId, 'target_type' => 'import_batch', 'target_id' => $batchId,
            'meta' => ['filename' => basename($filename), 'sha256' => $sha, 'counts' => $counts],
        ]);

        return ['batch_id' => $batchId, 'counts' => $counts, 'total' => count($out), 'mode' => $mode, 'already_committed_batch' => $alreadyCommitted];
    }

    /**
     * Step 2: commit a previewed batch in one transaction. Re-checks duplicates
     * against the database (another import may have run in between).
     *
     * @return array<string, int>
     */
    public function commit(int $batchId, Actor $actor, bool $confirmReimport = false): array
    {
        $batch = Db::row('SELECT * FROM import_batches WHERE id = ?', [$batchId]) ?? throw new DomainException('not_found', 'Import not found.', 404);
        if ($batch['status'] !== 'previewed') {
            throw new DomainException('already_done', 'This import was already committed or cancelled.', 409);
        }
        $dup = Db::value("SELECT id FROM import_batches WHERE slam_id = ? AND file_sha256 = ? AND status = 'committed' AND id <> ? LIMIT 1", [$batch['slam_id'], $batch['file_sha256'], $batchId]);
        if ($dup !== null && ! $confirmReimport) {
            throw new DomainException('reimport', 'This exact file was already imported for this slam. Confirm to import it again.', 409);
        }

        try {
            $result = Db::tx(function ($db) use ($batch, $actor): array {
                $slamId = (int) $batch['slam_id'];
                Db::lockSlam($slamId);
                $rows     = Db::rows("SELECT * FROM import_batch_rows WHERE batch_id = ? AND status IN ('new','duplicate') ORDER BY line_no", [$batch['id']]);
                $counts   = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => (int) $batch['rows_failed'], 'warned_checked_in' => 0];
                $reserved = [];
                $now      = Clock::db();
                foreach ($rows as $r) {
                    $existing = Db::row('SELECT * FROM attendees WHERE slam_id = ? AND mobile = ?', [$slamId, $r['mobile']]);
                    if ($existing !== null) {
                        if ($batch['mode'] === 'skip_existing') {
                            $counts['skipped']++;
                            continue;
                        }
                        // update_existing: name, email, type only; never badge_id, check-ins or reviews.
                        if (Db::value('SELECT id FROM checkins WHERE attendee_id = ? AND checked_out_at IS NULL', [$existing['id']]) !== null) {
                            $counts['warned_checked_in']++;
                        }
                        $db->table('attendees')->where('id', $existing['id'])->update([
                            'name' => $r['name'], 'email' => $r['email'], 'user_type' => $r['user_type'], 'updated_at' => $now,
                        ]);
                        $counts['updated']++;
                        continue;
                    }
                    $badge = $r['badge_id'];
                    if ($badge === null || service('badgeIds')->exists($badge) || isset($reserved[$badge])) {
                        if ($badge !== null) {
                            throw new DomainException('badge_conflict', "Line {$r['line_no']}: badge_id {$badge} is already in use.");
                        }
                        $badge = service('badgeIds')->generate($reserved);
                    }
                    $reserved[$badge] = true;
                    $db->table('attendees')->insert([
                        'slam_id' => $slamId, 'badge_id' => $badge, 'name' => $r['name'], 'mobile' => $r['mobile'],
                        'email' => $r['email'], 'user_type' => $r['user_type'], 'is_active' => 1,
                        'import_batch_id' => $batch['id'], 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $counts['created']++;
                }
                $db->table('import_batches')->where('id', $batch['id'])->update([
                    'status' => 'committed', 'committed_at' => $now, 'rows_created' => $counts['created'],
                    'rows_updated' => $counts['updated'], 'rows_skipped' => $counts['skipped'],
                ]);
                $db->table('import_batch_rows')->where('batch_id', $batch['id'])->delete();
                service('activityLog')->log($actor, 'attendee.import.committed', [
                    'slam_id' => $slamId, 'target_type' => 'import_batch', 'target_id' => (int) $batch['id'],
                    'meta' => ['filename' => $batch['filename'], 'sha256' => $batch['file_sha256']] + $counts,
                ]);

                return $counts;
            });
        } catch (Throwable $e) {
            db_connect()->table('import_batches')->where('id', $batchId)->update(['status' => 'failed']);
            service('activityLog')->log($actor, 'attendee.import.failed', [
                'slam_id' => (int) $batch['slam_id'], 'target_type' => 'import_batch', 'target_id' => $batchId, 'outcome' => 'failure',
                'reason' => $e instanceof DomainException ? $e->reason : 'error',
            ]);
            throw $e instanceof DomainException ? $e : new DomainException('import_failed', 'The import failed; nothing was saved.', 500);
        }

        return $result;
    }

    public function cancel(int $batchId, Actor $actor): void
    {
        $batch = Db::row('SELECT * FROM import_batches WHERE id = ?', [$batchId]) ?? throw new DomainException('not_found', 'Import not found.', 404);
        if ($batch['status'] !== 'previewed') {
            return;
        }
        Db::tx(function ($db) use ($batchId): void {
            $db->table('import_batches')->where('id', $batchId)->update(['status' => 'cancelled']);
            $db->table('import_batch_rows')->where('batch_id', $batchId)->delete();
        });
    }

    /** Error rows as CSV (CSV-injection safe). */
    public function errorsCsv(int $batchId): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['line', 'name', 'mobile', 'email', 'type', 'badge_id', 'error'], ',', '"', '');
        foreach (Db::rows("SELECT * FROM import_batch_rows WHERE batch_id = ? AND status <> 'new' ORDER BY line_no", [$batchId]) as $r) {
            fputcsv($fh, array_map([AttendeeService::class, 'csvSafe'], [
                (string) $r['line_no'], $r['name'], $r['mobile'], $r['email'], $r['user_type'], $r['badge_id'], $r['error'],
            ]), ',', '"', '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /** Housekeeping: preview rows older than 7 days are removed (cron). */
    public function purgeOldPreviews(): int
    {
        $cut = Clock::db(Clock::now()->modify('-7 days'));
        $db  = db_connect();
        $db->query("DELETE r FROM import_batch_rows r JOIN import_batches b ON b.id = r.batch_id WHERE b.created_at < ?", [$cut]);

        return $db->affectedRows();
    }
}
