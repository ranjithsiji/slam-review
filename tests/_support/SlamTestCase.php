<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Base for database tests: migrates every module, freezes the clock at
 * 2026-11-01 03:00 UTC (08:30 IST) and offers a ready-made slam.
 *
 * Fixture slam times (UTC):  opens 03:00 · gates 03:30–04:30 · reviews 04:45 · closes 11:30
 * Schedule: N performances, 5 min each, 2 min changeover, starting 04:45.
 */
abstract class SlamTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null; // all module namespaces

    protected Actor $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $cache          = config('Cache');
        $cache->handler = 'dummy';
        Services::resetSingle('cache');
        foreach (['slams', 'slamPhase', 'schedule', 'checkins', 'reviews', 'questions', 'liveState', 'scoring', 'markRuns', 'throttle', 'attendees', 'presenters', 'publicCache', 'activityLog'] as $s) {
            Services::resetSingle($s);
        }
        Clock::set('2026-11-01 03:00:00');
        $this->admin = new Actor('admin', $this->createAdmin(), 'admin');
    }

    protected function tearDown(): void
    {
        Clock::set(null);
        parent::tearDown();
    }

    protected function createAdmin(): int
    {
        return service('auth')->createAdmin('admin' . random_int(1000, 9999), 'Admin', 'a-long-password', Actor::system());
    }

    protected function seedTemplates(int $users = 2, int $judges = 3): void
    {
        $db = db_connect();
        for ($i = 1; $i <= $users; $i++) {
            $db->table('review_questions')->insert(['slam_id' => null, 'audience' => 'user', 'text' => "User Q{$i}", 'weight' => 1, 'position' => $i * 10, 'is_active' => 1]);
        }
        for ($i = 1; $i <= $judges; $i++) {
            $db->table('review_questions')->insert(['slam_id' => null, 'audience' => 'judge', 'text' => "Judge Q{$i}", 'weight' => 1, 'position' => $i * 10, 'is_active' => 1]);
        }
    }

    /**
     * Create a scheduled slam with $perfs performances and $users users + $judges judges.
     *
     * @return array{id:int, items:list<int>, perfs:list<int>, users:list<array>, judges:list<array>}
     */
    protected function makeSlam(int $perfs = 3, int $users = 4, int $judges = 2, array $overrides = []): array
    {
        if ((int) Db::value('SELECT COUNT(*) FROM review_questions WHERE slam_id IS NULL') === 0) {
            $this->seedTemplates();
        }
        $id = service('slams')->create($overrides + [
            'name' => 'Test Slam', 'type' => 'regional', 'slug' => 'test-slam-' . random_int(1000, 99999),
            'venue_name' => 'Hall', 'address_line' => 'Road', 'city' => 'Kochi', 'district' => 'Ernakulam',
            'opens_at' => '2026-11-01 03:00:00', 'checkin_opens_at' => '2026-11-01 03:30:00',
            'checkin_closes_at' => '2026-11-01 04:30:00', 'reviews_start_at' => '2026-11-01 04:45:00',
            'closes_at' => '2026-11-01 11:30:00',
            'default_review_minutes' => 5, 'changeover_minutes' => 2, 'edit_window_minutes' => 10, 'close_grace_minutes' => 5,
        ], $this->admin);
        $itemIds = [];
        $perfIds = [];
        for ($i = 1; $i <= $perfs; $i++) {
            $r = service('presenters')->addToSlam($id, ['name' => "Presenter {$i}", 'topic' => "Topic {$i}", 'abstract' => 'A'], $this->admin);
            $itemIds[] = $r['schedule_item_id'];
            $perfIds[] = $r['performance_id'];
        }
        $u = [];
        $j = [];
        for ($i = 0; $i < $users + $judges; $i++) {
            $aid = service('attendees')->create($id, [
                'name' => "Person {$i}", 'mobile' => (string) (9800000000 + $i + $id * 1000), 'user_type' => $i < $users ? 'user' : 'judge',
            ], $this->admin);
            $row = Db::row('SELECT * FROM attendees WHERE id = ?', [$aid]);
            if ($i < $users) {
                $u[] = $row;
            } else {
                $j[] = $row;
            }
        }
        service('slamPhase')->markReady($id, $this->admin);

        return ['id' => $id, 'items' => $itemIds, 'perfs' => $perfIds, 'users' => $u, 'judges' => $j];
    }

    protected function phase(int $slamId): string
    {
        return (string) Db::value('SELECT status FROM slams WHERE id = ?', [$slamId]);
    }

    protected function at(string $utc, int $slamId): array
    {
        Clock::set($utc);

        return service('slamPhase')->tick($slamId, true);
    }

    /** Self check-in and return the reviewer session payload. */
    protected function checkIn(array $attendee): array
    {
        $r = service('checkins')->selfCheckin($attendee['badge_id'], '10.0.0.' . random_int(1, 250));

        return ['attendee_id' => (int) $attendee['id'], 'checkin_id' => $r['checkin_id'], 'token' => $r['token'], 'badge_id' => $attendee['badge_id']];
    }

    protected function answers(int $slamId, string $audience, int $score): array
    {
        return array_map(
            static fn ($q) => ['question_id' => (int) $q['id'], 'score' => $score],
            service('questions')->active($slamId, $audience),
        );
    }

    protected function actorFor(array $attendee): Actor
    {
        return new Actor($attendee['user_type'], (int) $attendee['id'], $attendee['badge_id'], (int) $attendee['slam_id']);
    }
}
