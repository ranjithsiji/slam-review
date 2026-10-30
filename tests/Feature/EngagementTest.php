<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class EngagementTest extends SlamTestCase
{
    private function vote(array $a, int $item): void
    {
        $qs  = service('questions')->active((int) $a['slam_id'], $a['user_type']);
        $ans = array_map(static fn ($q) => ['question_id' => (int) $q['id'], 'score' => 3], $qs);
        service('reviews')->submit($a, $item, $ans, null, $this->actorFor($a));
    }

    public function testLiveTurnoutAndNotReviewingList(): void
    {
        $s = $this->makeSlam(3, 4, 0);
        [$u0, $u1, $u2, $u3] = $s['users'];
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        foreach ($s['users'] as $u) {
            $this->checkIn($u);
        }
        $this->assertNull(service('engagement')->turnout($id));

        // First talk: 2 of the 4 people inside vote. Turnout is live while the window is open.
        $this->at('2026-11-01 04:45:00', $id);
        $this->vote($u0, $s['items'][0]);
        $this->vote($u1, $s['items'][0]);
        $t = service('engagement')->turnout($id);
        $this->assertSame([$s['items'][0], true, 4, 2, 2, 50], [$t['item_id'], $t['open'], $t['checked_in'], $t['voted'], $t['not_voted'], $t['percent']]);

        // Second talk: only u0 votes. After the first window closes nobody is idle yet (needs 2 in a row).
        $this->at('2026-11-01 04:53:00', $id);
        $this->vote($u0, $s['items'][1]);
        $t = service('engagement')->turnout($id);
        $this->assertSame([$s['items'][1], true, 1], [$t['item_id'], $t['open'], $t['voted']]);
        $this->assertSame([], service('engagement')->idle($id, 2));
        // With one skipped review enough, the two who missed the finished first talk show up.
        $this->assertEqualsCanonicalizing([(int) $u2['id'], (int) $u3['id']], array_column(service('engagement')->idle($id, 1), 'attendee_id'));
    }

    public function testIdleNeedsConsecutiveMissesAndLateArrivalsAreNotPenalised(): void
    {
        $s = $this->makeSlam(3, 5, 0);
        [$u0, $u1, $u2, $u3, $late] = $s['users'];
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        foreach ([$u0, $u1, $u2, $u3] as $u) {
            $this->checkIn($u);
        }
        $this->at('2026-11-01 04:45:00', $id);
        $this->vote($u0, $s['items'][0]);
        $this->vote($u1, $s['items'][0]);
        $this->at('2026-11-01 04:53:00', $id);
        $this->vote($u0, $s['items'][1]);
        $this->vote($u2, $s['items'][1]);
        // Item 1 closes and item 2 opens: closed windows are item 0 and item 1.
        $this->at('2026-11-01 05:01:00', $id);
        $this->checkInLate($late, $id);

        $idle = array_column(service('engagement')->idle($id, 2), null, 'attendee_id');
        $this->assertSame([(int) $u3['id']], array_keys($idle));           // skipped both
        $this->assertSame([0, 2, 2], [$idle[(int) $u3['id']]['reviewed'], $idle[(int) $u3['id']]['eligible'], $idle[(int) $u3['id']]['missed']]);
        // u1 voted the first, skipped the second: one in a row. Late arrival: no closed window since arriving.
        $one = array_column(service('engagement')->idle($id, 1), 'attendee_id');
        $this->assertEqualsCanonicalizing([(int) $u1['id'], (int) $u3['id']], $one);

        // Force check-out: needs a reason, only affects people still idle, and ends their check-in.
        try {
            service('engagement')->checkoutIdle($id, 2, null, ' ', $this->admin);
            $this->fail('reason required');
        } catch (DomainException $e) {
            $this->assertSame('reason_required', $e->reason);
        }
        $this->assertSame(['checked_out' => 1, 'skipped' => 0], service('engagement')->checkoutIdle($id, 2, null, 'Not reviewing', $this->admin));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM checkins WHERE attendee_id = ? AND checked_out_at IS NOT NULL AND checkout_type = 'forced'", [$u3['id']]));
        $this->assertSame([], service('engagement')->idle($id, 2));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'checkout.forced' AND slam_id = ?", [$id]));
    }

    public function testPresenceCountsInsideLinkedPhonesAndVolunteersOnline(): void
    {
        // Tests run on a dummy cache; heartbeats need a real one (cleaned first, since volunteer ids repeat between runs).
        config('Cache')->handler = 'file';
        \Config\Services::resetSingle('cache');
        cache()->clean();
        try {
            $this->presenceBody();
        } finally {
            config('Cache')->handler = 'dummy';
            \Config\Services::resetSingle('cache');
        }
    }

    private function presenceBody(): void
    {
        $s = $this->makeSlam(2, 3, 2);
        $id = $s['id'];
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($s['users'][0]);
        $this->checkIn($s['users'][1]);
        $this->checkIn($s['judges'][0]);
        $vol = service('auth')->saveVolunteer(null, ['username' => 'desk.live', 'name' => 'Desk Live', 'slam_id' => $id, 'password' => 'volunteer-pass', 'is_active' => 1], $this->admin);
        service('auth')->saveVolunteer(null, ['username' => 'desk.idle', 'name' => 'Desk Idle', 'slam_id' => $id, 'password' => 'volunteer-pass', 'is_active' => 1], $this->admin);

        $p = service('engagement')->presence($id);
        $this->assertSame([2, 3], [$p['users']['inside'], $p['users']['total']]);
        $this->assertSame([1, 2], [$p['judges']['inside'], $p['judges']['total']]);
        $this->assertSame([0, 2], [$p['volunteers']['online'], $p['volunteers']['total']]);

        service('engagement')->volunteerHeartbeat($vol);
        $p = service('engagement')->presence($id);
        $this->assertSame([1, ['Desk Live']], [$p['volunteers']['online'], $p['volunteers']['names']]);
    }

    /** A person who arrives after the gate (late check-in) while reviews are running. */
    private function checkInLate(array $a, int $slamId): void
    {
        service('checkins')->lateCheckin((int) $a['id'], 'Arrived late', $this->admin);
    }
}
