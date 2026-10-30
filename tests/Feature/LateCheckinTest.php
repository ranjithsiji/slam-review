<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

/** After Gate Close: the person's phone waits, the volunteer checks them in, and exactly that phone moves on. */
final class LateCheckinTest extends SlamTestCase
{
    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainException $e) {
            return $e->reason;
        }

        return 'none';
    }

    private function vol(int $slamId, int $id = 1): Actor
    {
        return new Actor('volunteer', $id, 'desk' . $id, $slamId);
    }

    public function testPhoneWaitsAndTheVolunteersCheckInMovesThatPhoneOn(): void
    {
        $s = $this->makeSlam(2, 4, 0);
        $id = $s['id'];
        [$u0, $u1, $u2, $u3] = $s['users'];
        $svc = service('checkins');
        $this->at('2026-11-01 04:31:00', $id);
        $this->assertSame('checkin_closed', $this->phase($id));

        // Scanning the QR after Gate Close is no longer a dead end: the phone verifies, then waits.
        $this->assertTrue($svc->selfLookup($u0['badge_id'], '10.0.0.1')['late']);
        $r0 = $svc->requestLate($u0['badge_id']);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{2}$/', $r0['code']);
        $this->assertSame('waiting', $svc->waitingStatus($r0['id'], $r0['token'])['status']);
        $this->assertSame('unknown', $svc->waitingStatus($r0['id'], 'wrong-token')['status']);
        $list = $svc->waitingList($id);
        $this->assertSame([[(int) $u0['id'], $r0['code']]], array_map(static fn ($w) => [$w['attendee_id'], $w['code']], $list));
        $this->assertStringContainsString('•', $list[0]['mobile']); // masked on the desk list

        // The volunteer checks in the same badge: that waiting phone is bound and gets a valid review session.
        $res = $svc->lateCheckin((int) $u0['id'], null, $this->vol($id));
        $this->assertTrue($res['phone']['bound']);
        $this->assertSame($r0['code'], $res['phone']['code']);
        $st = $svc->waitingStatus($r0['id'], $r0['token']);
        $this->assertSame('approved', $st['status']);
        $session = ['attendee_id' => (int) $u0['id'], 'slam_id' => $id, 'badge_id' => $u0['badge_id'], 'checkin_id' => $st['checkin_id'], 'token' => $r0['token']];
        $this->assertNotNull($svc->validateSession($session));
        $this->assertSame([], $svc->waitingList($id));
        // Another device cannot use that check-in.
        $this->assertNull($svc->validateSession(['token' => 'someone-elses'] + $session));

        // Picking the phone from the desk list does the same, without scanning.
        $r1 = $svc->requestLate($u1['badge_id']);
        $out = $svc->approveRequest($r1['id'], $this->vol($id));
        $this->assertSame($r1['code'], $out['code']);
        $this->assertSame('approved', $svc->waitingStatus($r1['id'], $r1['token'])['status']);
        $this->assertSame('request_closed', $this->reason(fn () => $svc->approveRequest($r1['id'], $this->vol($id)))); // already used

        // Two phones claim the same person: the desk chooses by code; the other request is closed.
        $a = $svc->requestLate($u2['badge_id']);
        $b = $svc->requestLate($u2['badge_id']);
        $res = $svc->lateCheckin((int) $u2['id'], null, $this->vol($id));
        $this->assertFalse($res['phone']['bound']);
        $this->assertCount(2, $res['phone']['choices']);
        $svc->approveRequest($b['id'], $this->vol($id));      // the person was already checked in at the desk without a phone
        $this->assertSame('approved', $svc->waitingStatus($b['id'], $b['token'])['status']);
        $this->assertSame('cancelled', $svc->waitingStatus($a['id'], $a['token'])['status']);

        // A phone replacing its own earlier request cancels it.
        $c = $svc->requestLate($u3['badge_id']);
        $d = $svc->requestLate($u3['badge_id'], $c['id']);
        $this->assertSame('cancelled', $svc->waitingStatus($c['id'], $c['token'])['status']);
        $this->assertSame('waiting', $svc->waitingStatus($d['id'], $d['token'])['status']);
    }

    public function testGuardsScopeAndLifetime(): void
    {
        $s = $this->makeSlam(2, 2, 0);
        $other = $this->makeSlam(1, 1, 0);
        $id = $s['id'];
        [$u0, $u1] = $s['users'];
        $svc = service('checkins');

        // While the gates are open people check themselves in; the waiting flow is not for them.
        $this->at('2026-11-01 04:00:00', $id);
        $this->assertSame('self_checkin', $this->reason(fn () => $svc->requestLate($u0['badge_id'])));
        $this->assertSame('invalid_id', $this->reason(fn () => $svc->requestLate('ZZ00000')));

        $this->at('2026-11-01 04:31:00', $id);
        $r = $svc->requestLate($u0['badge_id']);
        // A volunteer of another slam cannot approve it.
        $this->assertSame('forbidden', $this->reason(fn () => $svc->approveRequest($r['id'], $this->vol($other['id'], 2))));

        // The desk only lists phones that are still on the waiting screen.
        Clock::set('2026-11-01 04:32:10');
        $this->assertSame([], $svc->waitingList($id));
        $svc->waitingStatus($r['id'], $r['token']);   // the phone polls again
        $this->assertCount(1, $svc->waitingList($id));

        // Requests expire.
        Clock::set('2026-11-01 04:55:00');
        $this->assertSame('expired', $svc->waitingStatus($r['id'], $r['token'])['status']);
        $this->assertSame('request_closed', $this->reason(fn () => $svc->approveRequest($r['id'], $this->vol($id))));

        // Checked out again after approval: the old phone no longer gets in.
        $r2 = $svc->requestLate($u1['badge_id']);
        $svc->approveRequest($r2['id'], $this->vol($id));
        $svc->checkout((int) $u1['id'], 'forced', $this->admin, 'test');
        $this->assertSame('cancelled', $svc->waitingStatus($r2['id'], $r2['token'])['status']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'checkin.phone_linked' AND slam_id = ?", [$id]));
    }
}
