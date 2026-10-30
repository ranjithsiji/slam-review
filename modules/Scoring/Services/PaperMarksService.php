<?php

declare(strict_types=1);

namespace Modules\Scoring\Services;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Modules\Reviews\Services\ReviewService;

/**
 * Marks written on paper, typed in by the organisers: audience ballots (one row per person and talk) and judges' marks,
 * by hand or from a CSV. Everything is validated first; a file with any error is rejected as a whole. Entered marks keep
 * the same history as phone reviews and the final marks are recalculated by the software afterwards.
 */
class PaperMarksService
{
    public const MAX_ROWS  = 2000;
    public const PHASES    = ['reviewing', 'reviews_complete', 'revision', 'revision_closed', 'closed', 'marks_calculated'];

    private function assertOpen(array $slam): void
    {
        if (! in_array($slam['status'], self::PHASES, true)) {
            throw new DomainException('forbidden_transition', $slam['status'] === 'ended' ? 'The event has ended; marks are final.' : 'Paper marks can be entered once reviewing has started.', 409);
        }
    }

    /** performance name (lower case) and performance id → schedule item id, for the talks that were reviewed. */
    private function itemMap(int $slamId): array
    {
        $map = [];
        $opened = array_flip(service('scoring')->openedItemIds($slamId));
        foreach (Db::rows("SELECT si.id AS item_id, p.id AS perf_id, pr.name FROM schedule_items si JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id WHERE si.slam_id = ? AND si.item_type = 'performance'", [$slamId]) as $r) {
            if (isset($opened[(int) $r['item_id']])) {
                $map['n:' . mb_strtolower(trim($r['name']))] = (int) $r['item_id'];
                $map['i:' . $r['perf_id']] = (int) $r['item_id'];
            }
        }

        return $map;
    }

    /**
     * Audience ballots for one talk.
     *
     * @param list<array{badge_id: string, scores: list<string|int>, line?: int}> $rows
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function saveAudience(int $slamId, int $itemId, array $rows, Actor $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('forbidden', 'Only an administrator or operator can enter paper marks.', 403);
        }
        $res = Db::tx(function ($db) use ($slamId, $itemId, $rows, $actor): array {
            $slam = Db::lockSlam($slamId);
            $this->assertOpen($slam);
            if (! in_array($itemId, service('scoring')->openedItemIds($slamId), true)) {
                throw new DomainException('validation', 'That performance has not been reviewed yet.', 422);
            }
            $qs     = service('questions')->active($slamId, 'user');
            $errors = [];
            $clean  = [];
            foreach ($rows as $i => $r) {
                $line  = $r['line'] ?? $i + 1;
                $badge = strtoupper(trim((string) $r['badge_id']));
                $att   = Db::row("SELECT id FROM attendees WHERE badge_id = ? AND slam_id = ? AND user_type = 'user' AND is_active = 1", [$badge, $slamId]);
                if ($att === null) {
                    $errors[] = "Line {$line}: {$badge} is not an audience member of this slam.";
                    continue;
                }
                if (count($r['scores']) !== count($qs)) {
                    $errors[] = "Line {$line}: expected " . count($qs) . ' marks (one per question), found ' . count($r['scores']) . '.';
                    continue;
                }
                $vals = [];
                foreach ($qs as $k => $q) {
                    $v = ReviewService::parseScore(trim((string) $r['scores'][$k]), 'user', (int) $q['max_score']);
                    if ($v === null) {
                        $errors[] = "Line {$line}: mark " . ($k + 1) . ' must be a whole number from 1 to ' . (int) $q['max_score'] . '.';
                        continue 2;
                    }
                    $vals[(int) $q['id']] = $v;
                }
                $clean[(int) $att['id']] = $vals; // a later line for the same person wins
            }
            if ($errors !== []) {
                throw new DomainException('validation', count($errors) . ' problem(s): nothing was saved.', 422, ['rows' => $errors]);
            }
            $now      = Clock::db();
            $windowId = (int) Db::value('SELECT id FROM review_windows WHERE schedule_item_id = ? ORDER BY id DESC LIMIT 1', [$itemId]);
            $out      = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($clean as $attendeeId => $vals) {
                $review = Db::row('SELECT * FROM reviews WHERE attendee_id = ? AND schedule_item_id = ? FOR UPDATE', [$attendeeId, $itemId]);
                if ($review === null) {
                    $db->table('reviews')->insert(['attendee_id' => $attendeeId, 'schedule_item_id' => $itemId, 'review_window_id' => $windowId, 'submitted_at' => $now, 'entered_by' => $actor->id]);
                    $rid = (int) $db->insertID();
                    foreach ($vals as $qid => $score) {
                        $db->table('review_answers')->insert(['review_id' => $rid, 'question_id' => $qid, 'score' => $score]);
                    }
                    $out['created']++;
                    continue;
                }
                $changed = false;
                foreach ($vals as $qid => $score) {
                    $old = Db::value('SELECT score FROM review_answers WHERE review_id = ? AND question_id = ?', [$review['id'], $qid]);
                    if ($old === null) {
                        $db->table('review_answers')->insert(['review_id' => $review['id'], 'question_id' => $qid, 'score' => $score]);
                        $changed = true;
                    } elseif (abs((float) $old - (float) $score) > 1e-9) {
                        $db->table('review_answers')->where('review_id', $review['id'])->where('question_id', $qid)->update(['score' => $score]);
                        $db->table('review_revisions')->insert(['review_id' => $review['id'], 'question_id' => $qid, 'old_score' => $old, 'new_score' => $score, 'revision_window_id' => null, 'changed_at' => $now]);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $db->table('reviews')->where('id', $review['id'])->update(['edited_at' => $now, 'edit_count' => (int) $review['edit_count'] + 1, 'entered_by' => $actor->id]);
                    $out['updated']++;
                } else {
                    $out['unchanged']++;
                }
            }
            service('questions')->lockAudience($slamId, 'user', $actor);
            service('activityLog')->log($actor, 'paper_marks.entered', ['slam_id' => $slamId, 'target_type' => 'schedule_item', 'target_id' => $itemId, 'meta' => ['kind' => 'audience'] + $out]);

            return $out;
        });
        service('judgeMarks')->recalculateIfClosed($slamId, $actor);

        return $res;
    }

    /** Lines like "KR48213, 4, 5" (comma, semicolon, tab or space separated). */
    public function parseLines(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\t ]+/u', $line) ?: []), static fn ($p) => $p !== ''));
            $rows[] = ['badge_id' => (string) array_shift($parts), 'scores' => $parts, 'line' => $i + 1];
        }

        return $rows;
    }

    /**
     * Import a CSV. kind 'audience': badge_id, presenter (or performance_id), then one column per question (q1, q2 ...).
     * kind 'judge': badge_id (the judge), presenter (or performance_id), mark (0.1 to 10).
     *
     * @return array{rows: int, created: int, updated: int, judges: int}
     */
    public function importCsv(int $slamId, string $kind, string $tmpPath, Actor $actor): array
    {
        if (! in_array($kind, ['audience', 'judge'], true)) {
            throw new DomainException('validation', 'Choose what the file contains.', 422);
        }
        if (! is_file($tmpPath) || filesize($tmpPath) === 0 || filesize($tmpPath) > 2_000_000) {
            throw new DomainException('validation', 'Upload a CSV file of up to 2 MB.', 422);
        }
        $this->assertOpen(service('slams')->find($slamId));
        $text = (string) file_get_contents($tmpPath);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new DomainException('validation', 'The file must be UTF-8 text (save as "CSV UTF-8").', 422);
        }
        $text  = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $first = strtok($text, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $fh    = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim, '"', '');
        $cols   = array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []);
        $col    = static fn (array $names) => (function () use ($cols, $names) { foreach ($names as $n) { $i = array_search($n, $cols, true); if ($i !== false) { return $i; } } return null; })();
        $iBadge = $col(['badge_id', 'badge', 'id']);
        $iPres  = $col(['presenter', 'presenter_name', 'name']);
        $iPerf  = $col(['performance_id', 'performance']);
        if ($iBadge === null || ($iPres === null && $iPerf === null)) {
            throw new DomainException('validation', 'The first row must name the columns: badge_id, presenter (or performance_id) and the marks.', 422);
        }
        $qs    = service('questions')->active($slamId, 'user');
        $iMark = $col(['mark', 'total', 'score']);
        $qCols = [];
        if ($kind === 'audience') {
            foreach ($qs as $k => $q) {
                $idx = $col(['q' . ($k + 1), 'score_' . ($k + 1), 'score' . ($k + 1)]);
                if ($idx === null) {
                    throw new DomainException('validation', 'Missing the column q' . ($k + 1) . ' (one column per question).', 422);
                }
                $qCols[] = $idx;
            }
        } elseif ($iMark === null) {
            throw new DomainException('validation', 'Missing the column: mark.', 422);
        }

        $map = $this->itemMap($slamId);
        $errors = [];
        $audience = [];  // item => rows
        $judges   = [];  // judge attendee id => [item => mark]
        $n = 0;
        $line = 1;
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || implode('', array_map('trim', $r)) === '') {
                continue;
            }
            if (++$n > self::MAX_ROWS) {
                throw new DomainException('validation', 'At most ' . self::MAX_ROWS . ' rows per file.', 422);
            }
            $item = null;
            if ($iPerf !== null && trim((string) ($r[$iPerf] ?? '')) !== '') {
                $item = $map['i:' . (int) $r[$iPerf]] ?? null;
            }
            if ($item === null && $iPres !== null) {
                $item = $map['n:' . mb_strtolower(trim((string) ($r[$iPres] ?? '')))] ?? null;
            }
            if ($item === null) {
                $errors[] = "Line {$line}: no reviewed talk matches \"" . trim((string) ($r[$iPres ?? $iPerf] ?? '')) . '".';
                continue;
            }
            $badge = strtoupper(trim((string) ($r[$iBadge] ?? '')));
            if ($kind === 'audience') {
                $audience[$item][] = ['badge_id' => $badge, 'scores' => array_map(static fn ($i) => (string) ($r[$i] ?? ''), $qCols), 'line' => $line];
            } else {
                $j = Db::row("SELECT id FROM attendees WHERE badge_id = ? AND slam_id = ? AND user_type = 'judge' AND is_active = 1", [$badge, $slamId]);
                if ($j === null) {
                    $errors[] = "Line {$line}: {$badge} is not a judge of this slam.";
                    continue;
                }
                $judges[(int) $j['id']][$item] = str_replace(',', '.', trim((string) ($r[$iMark] ?? '')));
            }
        }
        fclose($fh);
        if ($errors !== []) {
            throw new DomainException('validation', count($errors) . ' problem(s) in the file: nothing was imported.', 422, ['rows' => array_slice($errors, 0, 50)]);
        }
        if ($audience === [] && $judges === []) {
            throw new DomainException('validation', 'The file has no data rows.', 422);
        }

        return Db::tx(function () use ($slamId, $kind, $audience, $judges, $actor, $n): array {
            $out = ['rows' => $n, 'created' => 0, 'updated' => 0, 'judges' => count($judges)];
            foreach ($audience as $item => $rows) {
                $r = $this->saveAudience($slamId, (int) $item, $rows, $actor);
                $out['created'] += $r['created'];
                $out['updated'] += $r['updated'];
            }
            foreach ($judges as $judgeId => $marks) {
                $r = service('judgeMarks')->save($slamId, (int) $judgeId, $marks, $actor);
                $out['created'] += $r['saved'];
            }

            return $out;
        });
    }

    public function template(string $kind, int $slamId): string
    {
        $one = Db::row("SELECT pr.name FROM schedule_items si JOIN performances p ON p.id = si.performance_id JOIN presenters pr ON pr.id = p.presenter_id WHERE si.slam_id = ? AND si.item_type = 'performance' ORDER BY si.position LIMIT 1", [$slamId])['name'] ?? 'Presenter name';
        if ($kind === 'judge') {
            $badge = Db::value("SELECT badge_id FROM attendees WHERE slam_id = ? AND user_type = 'judge' ORDER BY id LIMIT 1", [$slamId]) ?? 'AB12345';

            return "badge_id,presenter,mark\n{$badge},\"{$one}\",7.5\n";
        }
        $qs    = service('questions')->active($slamId, 'user');
        $heads = implode(',', array_map(static fn ($i) => 'q' . ($i + 1), array_keys($qs)));
        $badge = Db::value("SELECT badge_id FROM attendees WHERE slam_id = ? AND user_type = 'user' ORDER BY id LIMIT 1", [$slamId]) ?? 'AB12345';

        return "badge_id,presenter,{$heads}\n{$badge},\"{$one}\"," . implode(',', array_fill(0, count($qs), '4')) . "\n";
    }
}
