<?php

declare(strict_types=1);

namespace Modules\Attendees\Services;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Pre-registered attendees (users and judges). Admin single add/edit, ID
 * reissue, batch deactivation. Bulk registration is in ImportService.
 */
class AttendeeService
{
    /** Normalise an Indian mobile to 10 digits, or null if invalid. */
    public static function normaliseMobile(string $raw): ?string
    {
        $d = (string) preg_replace('/\D+/', '', $raw);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return Db::row('SELECT * FROM attendees WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Attendee not found.', 404);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(int $slamId, array $input, ?int $id): array
    {
        $errors = [];
        $name   = trim((string) ($input['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Name must be 2–120 characters.';
        }
        $mobile = self::normaliseMobile((string) ($input['mobile'] ?? ''));
        if ($mobile === null) {
            $errors['mobile'] = 'Mobile must be a valid 10-digit Indian number.';
        } elseif (Db::value('SELECT id FROM attendees WHERE slam_id = ? AND mobile = ? AND id <> ?', [$slamId, $mobile, $id ?? 0]) !== null) {
            $errors['mobile'] = 'This mobile is already registered for this slam.';
        }
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email.';
        }
        $type = (string) ($input['user_type'] ?? 'user');
        if (! in_array($type, ['user', 'judge'], true)) {
            $errors['user_type'] = 'Type must be user or judge.';
        }
        if ($errors !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $errors);
        }

        return ['name' => $name, 'mobile' => $mobile, 'email' => $email === '' ? null : $email, 'user_type' => $type];
    }

    /** @param array<string, mixed> $input */
    public function create(int $slamId, array $input, Actor $actor): int
    {
        $data = $this->validate($slamId, $input, null);

        return Db::tx(function ($db) use ($slamId, $data, $actor): int {
            $slam = Db::lockSlam($slamId);
            if ($slam['status'] === 'ended' || $slam['status'] === 'marks_calculated' || $slam['status'] === 'closed') {
                throw new DomainException('read_only', 'The slam is closed; attendees cannot be added.');
            }
            $badge = service('badgeIds')->generate();
            $db->table('attendees')->insert($data + ['slam_id' => $slamId, 'badge_id' => $badge, 'is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
            $id = (int) $db->insertID();
            service('activityLog')->log($actor, 'attendee.created', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $id, 'new' => ['name' => $data['name'], 'user_type' => $data['user_type']]]);
            service('activityLog')->log($actor, 'badge.generated', ['slam_id' => $slamId, 'target_type' => 'attendee', 'target_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input, Actor $actor): void
    {
        $old  = $this->find($id);
        $data = $this->validate((int) $old['slam_id'], $input, $id);
        $data['is_active'] = ! empty($input['is_active']) ? 1 : 0;
        Db::tx(function ($db) use ($id, $old, $data, $actor): void {
            $db->table('attendees')->where('id', $id)->update($data + ['updated_at' => Clock::db()]);
            [$o, $n] = ActivityLogService::diff($old, $data);
            if ($n !== []) {
                $action = ((int) $old['is_active'] === 1 && $data['is_active'] === 0) ? 'attendee.deactivated' : 'attendee.updated';
                // mobile/email are PII: record that they changed, not the values.
                foreach (['mobile', 'email'] as $pii) {
                    if (array_key_exists($pii, $n)) {
                        $o[$pii] = '(changed)';
                        $n[$pii] = '(changed)';
                    }
                }
                service('activityLog')->log($actor, $action, ['slam_id' => (int) $old['slam_id'], 'target_type' => 'attendee', 'target_id' => $id, 'old' => $o, 'new' => $n]);
            }
            if ($data['is_active'] === 0) {
                service('checkins')->endSessionsFor($id, $actor, 'attendee_deactivated');
            }
        });
    }

    /** New badge ID: the old one stops working at once and any open session ends. */
    public function reissueId(int $id, string $reason, Actor $actor): string
    {
        if (trim($reason) === '') {
            throw new DomainException('reason_required', 'A reason is required to reissue an ID.');
        }
        $att = $this->find($id);

        return Db::tx(function ($db) use ($att, $reason, $actor): string {
            $new = service('badgeIds')->generate();
            $db->table('attendees')->where('id', $att['id'])->update(['badge_id' => $new, 'badge_print_count' => 0, 'updated_at' => Clock::db()]);
            service('checkins')->endSessionsFor((int) $att['id'], $actor, 'id_reissued');
            service('activityLog')->log($actor, 'badge.id_reissued', ['slam_id' => (int) $att['slam_id'], 'target_type' => 'attendee', 'target_id' => (int) $att['id'], 'reason' => $reason]);

            return $new;
        });
    }

    /** Deactivate a whole import batch, only if nobody in it has checked in or reviewed. */
    public function deactivateBatch(int $batchId, Actor $actor): int
    {
        $batch = Db::row('SELECT * FROM import_batches WHERE id = ?', [$batchId]) ?? throw new DomainException('not_found', 'Batch not found.', 404);
        $used  = (int) Db::value(
            'SELECT COUNT(*) FROM attendees a WHERE a.import_batch_id = ? AND (EXISTS (SELECT 1 FROM checkins c WHERE c.attendee_id = a.id) OR EXISTS (SELECT 1 FROM reviews r WHERE r.attendee_id = a.id))',
            [$batchId],
        );
        if ($used > 0) {
            throw new DomainException('batch_in_use', "{$used} attendee(s) in this batch have checked in or reviewed; the batch cannot be deactivated.", 409);
        }

        return Db::tx(function ($db) use ($batch, $actor): int {
            $db->table('attendees')->where('import_batch_id', $batch['id'])->update(['is_active' => 0, 'updated_at' => Clock::db()]);
            $n = $db->affectedRows();
            service('activityLog')->log($actor, 'attendee.import.batch_deactivated', ['slam_id' => (int) $batch['slam_id'], 'target_type' => 'import_batch', 'target_id' => (int) $batch['id'], 'meta' => ['count' => $n]]);

            return $n;
        });
    }

    /** Count a print/export of these attendees' badges. */
    public function markPrinted(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        db_connect()->table('attendees')->whereIn('id', $ids)
            ->set('badge_print_count', 'badge_print_count + 1', false)
            ->set('badge_last_printed_at', Clock::db())
            ->update();
    }

    /** Strip leading formula characters for CSV export (CSV-injection protection). */
    public static function csvSafe(?string $v): string
    {
        return ltrim((string) $v, "=+-@\t\r");
    }
}
