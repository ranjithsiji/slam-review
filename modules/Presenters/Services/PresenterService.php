<?php

declare(strict_types=1);

namespace Modules\Presenters\Services;

use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;

/**
 * Presenters (the person, reusable across slams) and performances (their talk
 * in one slam). Reviews and schedule items attach to the performance.
 */
class PresenterService
{
    /** @return array<string, mixed> */
    public function validatePresenter(array $in): array
    {
        $e    = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'Name must be 2–120 characters.';
        }
        $profile = trim((string) ($in['profile'] ?? ''));
        if (mb_strlen($profile) > 1000) {
            $e['profile'] = 'Profile can be at most 1,000 characters.';
        }
        $desig = trim((string) ($in['designation_or_institution'] ?? ''));
        if (mb_strlen($desig) > 200) {
            $e['designation_or_institution'] = 'At most 200 characters.';
        }
        if ($e !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $e);
        }

        return [
            'name' => $name, 'profile' => $profile === '' ? null : $profile,
            'designation_or_institution' => $desig === '' ? null : $desig,
            'photo_alt' => trim((string) ($in['photo_alt'] ?? '')) ?: null,
        ];
    }

    /** @return array<string, mixed> */
    public function validatePerformance(array $in): array
    {
        $e     = [];
        $topic = trim((string) ($in['topic'] ?? ''));
        if (mb_strlen($topic) < 2 || mb_strlen($topic) > 200) {
            $e['topic'] = 'Topic must be 2–200 characters.';
        }
        $abstract = trim((string) ($in['abstract'] ?? ''));
        if (mb_strlen($abstract) > 2000) {
            $e['abstract'] = 'Abstract can be at most 2,000 characters.';
        }
        if ($e !== []) {
            throw new DomainException('validation', 'Please correct the highlighted fields.', 422, $e);
        }

        return ['topic' => $topic, 'abstract' => $abstract === '' ? null : $abstract];
    }

    /**
     * Add a presenter to a slam: new presenter (or existing presenter_id), the
     * performance, and optionally its schedule slot.
     *
     * @return array{presenter_id: int, performance_id: int, schedule_item_id: int|null}
     */
    public function addToSlam(int $slamId, array $in, Actor $actor, ?string $photoTmp = null, bool $withSlot = true): array
    {
        $perf     = $this->validatePerformance($in);
        $existing = ! empty($in['presenter_id']) ? (int) $in['presenter_id'] : null;
        $presenter = $existing === null ? $this->validatePresenter($in) : null;
        $photo    = $photoTmp !== null ? service('photos')->store($photoTmp) : null;

        try {
            $ids = Db::tx(function ($db) use ($slamId, $perf, $existing, $presenter, $photo, $actor): array {
                $slam = Db::lockSlam($slamId);
                if (in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true)) {
                    throw new DomainException('read_only', 'The slam is closed.');
                }
                if ($existing !== null) {
                    if (Db::row('SELECT id FROM presenters WHERE id = ?', [$existing]) === null) {
                        throw new DomainException('not_found', 'Presenter not found.', 404);
                    }
                    if (Db::value('SELECT id FROM performances WHERE slam_id = ? AND presenter_id = ?', [$slamId, $existing]) !== null) {
                        throw new DomainException('validation', 'This presenter is already in this slam.', 422, ['presenter_id' => 'Already in this slam.']);
                    }
                    $pid = $existing;
                } else {
                    $db->table('presenters')->insert($presenter + ($photo ?? []) + ['is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
                    $pid = (int) $db->insertID();
                    service('activityLog')->log($actor, 'presenter.created', ['slam_id' => $slamId, 'target_type' => 'presenter', 'target_id' => $pid, 'new' => ['name' => $presenter['name']]]);
                }
                $db->table('performances')->insert($perf + ['slam_id' => $slamId, 'presenter_id' => $pid, 'is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
                $perfId = (int) $db->insertID();
                service('activityLog')->log($actor, $existing !== null ? 'performance.added_from_existing_presenter' : 'performance.created', [
                    'slam_id' => $slamId, 'target_type' => 'performance', 'target_id' => $perfId, 'new' => $perf,
                ]);

                return ['presenter_id' => $pid, 'performance_id' => $perfId];
            });
        } catch (\Throwable $e) {
            if ($photo !== null) {
                service('photos')->delete($photo['photo_path'], $photo['photo_thumb_path']);
            }
            throw $e;
        }

        $itemId = null;
        if ($withSlot) {
            $itemId = service('schedule')->appendItem($slamId, [
                'item_type' => 'performance', 'performance_id' => $ids['performance_id'],
                'planned_start_at' => $in['planned_start_at'] ?? null, 'duration_minutes' => $in['duration_minutes'] ?? null,
            ], $actor);
        }

        return $ids + ['schedule_item_id' => $itemId];
    }

    public function updatePresenter(int $id, array $in, Actor $actor, ?string $photoTmp = null): void
    {
        $old  = Db::row('SELECT * FROM presenters WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Presenter not found.', 404);
        $data = $this->validatePresenter($in);
        $photo = $photoTmp !== null ? service('photos')->store($photoTmp) : null;
        Db::tx(function ($db) use ($id, $old, $data, $photo, $actor): void {
            $db->table('presenters')->where('id', $id)->update($data + ($photo ?? []) + ['updated_at' => Clock::db()]);
            [$o, $n] = ActivityLogService::diff($old, $data);
            if ($n !== []) {
                service('activityLog')->log($actor, 'presenter.updated', ['target_type' => 'presenter', 'target_id' => $id, 'old' => $o, 'new' => $n]);
            }
            if ($photo !== null) {
                service('activityLog')->log($actor, 'presenter.photo_replaced', ['target_type' => 'presenter', 'target_id' => $id]);
            }
        });
        if ($photo !== null) {
            service('photos')->delete($old['photo_path'], $old['photo_thumb_path']);
        }
        $this->purgeSlamsOfPresenter($id);
    }

    public function removePhoto(int $id, Actor $actor): void
    {
        $old = Db::row('SELECT * FROM presenters WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Presenter not found.', 404);
        db_connect()->table('presenters')->where('id', $id)->update(['photo_path' => null, 'photo_thumb_path' => null, 'updated_at' => Clock::db()]);
        service('photos')->delete($old['photo_path'], $old['photo_thumb_path']);
        service('activityLog')->log($actor, 'presenter.photo_replaced', ['target_type' => 'presenter', 'target_id' => $id, 'meta' => ['removed' => true]]);
        $this->purgeSlamsOfPresenter($id);
    }

    public function updatePerformance(int $id, array $in, Actor $actor): void
    {
        $old  = Db::row('SELECT * FROM performances WHERE id = ?', [$id]) ?? throw new DomainException('not_found', 'Performance not found.', 404);
        $data = $this->validatePerformance($in);
        $active = array_key_exists('is_active', $in) ? (int) (bool) $in['is_active'] : (int) $old['is_active'];
        Db::tx(function ($db) use ($id, $old, $data, $active, $actor): void {
            $slam = Db::lockSlam((int) $old['slam_id']);
            $db->table('performances')->where('id', $id)->update($data + ['is_active' => $active, 'updated_at' => Clock::db()]);
            [$o, $n] = ActivityLogService::diff($old, $data);
            if ($n !== []) {
                service('activityLog')->log($actor, 'performance.updated', ['slam_id' => (int) $old['slam_id'], 'target_type' => 'performance', 'target_id' => $id, 'old' => $o, 'new' => $n,
                    'meta' => ['has_reviews' => (int) Db::value('SELECT COUNT(*) FROM reviews r JOIN schedule_items si ON si.id = r.schedule_item_id WHERE si.performance_id = ?', [$id]) > 0]]);
            }
            if ((int) $old['is_active'] === 1 && $active === 0) {
                // Deactivating: pending slots are skipped so the schedule and marks stay intact.
                if (Db::value("SELECT id FROM schedule_items WHERE performance_id = ? AND status = 'open'", [$id]) !== null) {
                    throw new DomainException('locked_slot', 'This performance is being reviewed right now.', 409);
                }
                $db->table('schedule_items')->where('performance_id', $id)->where('status', 'pending')->update(['status' => 'skipped', 'updated_at' => Clock::db()]);
                $db->query('UPDATE slams SET schedule_version = schedule_version + 1 WHERE id = ?', [$slam['id']]);
                service('activityLog')->log($actor, 'performance.deactivated', ['slam_id' => (int) $old['slam_id'], 'target_type' => 'performance', 'target_id' => $id]);
            }
        });
        service('publicCache')->purge((int) $old['slam_id']);
    }

    private function purgeSlamsOfPresenter(int $presenterId): void
    {
        foreach (Db::rows('SELECT DISTINCT slam_id FROM performances WHERE presenter_id = ?', [$presenterId]) as $r) {
            service('publicCache')->purge((int) $r['slam_id']);
        }
    }

    /** Presenters not yet in this slam (schedule maker pool / "add existing presenter"). */
    public function pool(int $slamId, string $q = ''): array
    {
        $sql   = 'SELECT pr.id, pr.name, pr.designation_or_institution, pr.photo_thumb_path,
                         (SELECT p2.topic FROM performances p2 WHERE p2.presenter_id = pr.id ORDER BY p2.id DESC LIMIT 1) AS last_topic
                  FROM presenters pr WHERE pr.is_active = 1 AND NOT EXISTS (SELECT 1 FROM performances p WHERE p.presenter_id = pr.id AND p.slam_id = ?)';
        $binds = [$slamId];
        if (mb_strlen(trim($q)) >= 2) {
            $sql    .= ' AND (pr.name LIKE ? OR EXISTS (SELECT 1 FROM performances p3 WHERE p3.presenter_id = pr.id AND p3.topic LIKE ?))';
            $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($q)) . '%';
            $binds[] = $like;
            $binds[] = $like;
        }

        return Db::rows($sql . ' ORDER BY pr.name LIMIT 50', $binds);
    }
}
