<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class CheckinReviewTest extends SlamTestCase
{
    private function expectReason(string $reason, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$reason}");
        } catch (DomainException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function testCheckinOnlyWhileGateOpen(): void
    {
        $s = $this->makeSlam(1);
        $this->at('2026-11-01 03:10:00', $s['id']);
        $this->expectReason('gate_not_open', fn () => service('checkins')->selfCheckin($s['users'][0]['badge_id'], '1.1.1.1'));
        $this->at('2026-11-01 03:30:00', $s['id']);
        $r = service('checkins')->selfCheckin($s['users'][0]['badge_id'], '1.1.1.1');
        $this->assertGreaterThan(0, $r['checkin_id']);
        $this->at('2026-11-01 04:30:00', $s['id']);
        $this->expectReason('gate_closed', fn () => service('checkins')->selfCheckin($s['users'][1]['badge_id'], '1.1.1.1'));
    }

    public function testUnknownIdIsGenericAndCountsTowardsLockout(): void
    {
        $s = $this->makeSlam(1);
        $this->at('2026-11-01 03:30:00', $s['id']);
        for ($i = 0; $i < 5; $i++) {
            try {
                service('checkins')->selfCheckin('KR00001', '2.2.2.2');
            } catch (DomainException $e) {
                $this->assertSame('invalid_id', $e->reason);
                $this->assertStringNotContainsString('not registered', strtolower($e->getMessage()));
            }
        }
        $this->expectReason('locked_out', fn () => service('checkins')->selfCheckin('KR00001', '2.2.2.2'));
    }

    public function testSecondDeviceRejectedSameDeviceResumes(): void
    {
        $s = $this->makeSlam(1);
        $this->at('2026-11-01 03:30:00', $s['id']);
        $sess = $this->checkIn($s['users'][0]);
        $this->expectReason('already_checked_in', fn () => service('checkins')->selfCheckin($s['users'][0]['badge_id'], '3.3.3.3'));
        $again = service('checkins')->selfCheckin($s['users'][0]['badge_id'], '3.3.3.3', $sess);
        $this->assertTrue($again['resumed']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM checkins'));
    }

    public function testForcedCheckoutEndsSession(): void
    {
        $s = $this->makeSlam(1);
        $this->at('2026-11-01 03:30:00', $s['id']);
        $sess = $this->checkIn($s['users'][0]);
        $this->assertNotNull(service('checkins')->validateSession($sess));
        $this->expectReason('reason_required', fn () => service('checkins')->checkout((int) $s['users'][0]['id'], 'forced', $this->admin, ''));
        service('checkins')->checkout((int) $s['users'][0]['id'], 'forced', $this->admin, 'Badge shared');
        $this->assertNull(service('checkins')->validateSession($sess));
    }

    public function testSelfCheckinShowsMaskedNameAndMobileAndNothingElse(): void
    {
        $s = $this->makeSlam(1, 2, 0);
        $a = $s['users'][0];
        // Before the gate opens a scan is refused with the opening time.
        $this->expectReason('gate_not_open', fn () => service('checkins')->selfLookup($a['badge_id'], '5.5.5.5'));
        $this->at('2026-11-01 03:30:00', $s['id']);
        $who = service('checkins')->selfLookup('https://x.example/in/' . strtolower($a['badge_id']), '5.5.5.5');
        $this->assertSame($a['name'], $who['name']);
        $this->assertMatchesRegularExpression('/^\d{2}•+\d{4}$/u', $who['mobile']);
        $this->assertStringNotContainsString(substr($a['mobile'], 2, 4), $who['mobile']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM checkins'), 'the lookup must not check anyone in');
        // Unknown IDs get the generic error and count towards the lockout.
        $this->expectReason('invalid_id', fn () => service('checkins')->selfLookup('ZZ00000', '5.5.5.5'));
        $this->assertSame('98••••3210', \Modules\Checkin\Services\CheckinService::maskMobile('9876543210'));
    }

    public function testRegistrationLookupByMobileIsMinimal(): void
    {
        $s = $this->makeSlam(1, 2, 0);
        $a = $s['users'][0];
        $rows = service('checkins')->registrationLookup(' +91 ' . substr($a['mobile'], 0, 5) . '-' . substr($a['mobile'], 5));
        $this->assertCount(1, $rows);
        $this->assertSame(['slam_name', 'opens_at', 'venue_name', 'user_type'], array_keys($rows[0]));
        $this->assertSame([], service('checkins')->registrationLookup('9000000001'));
        $this->expectReason('validation', fn () => service('checkins')->registrationLookup('12345'));
    }

    public function testLateCheckinByVolunteerOrAdminOnlyAfterGateClose(): void
    {
        $s   = $this->makeSlam(1, 3, 0);
        $vol = new \Modules\Core\Libraries\Actor('volunteer', 1, 'v', $s['id']);
        $other = new \Modules\Core\Libraries\Actor('volunteer', 2, 'v2', $s['id'] + 99);
        $checkins = service('checkins');

        // While the gates are open the attendee checks in themself, not the desk.
        $this->at('2026-11-01 03:30:00', $s['id']);
        $this->expectReason('self_checkin', fn () => $checkins->lateCheckin((int) $s['users'][0]['id'], null, $vol));
        $this->at('2026-11-01 04:30:00', $s['id']);
        $this->assertSame('checkin_closed', $this->phase($s['id']));
        // Normal self check-in is now refused.
        $this->expectReason('gate_closed', fn () => $checkins->selfLookup($s['users'][0]['badge_id'], '6.6.6.6'));
        // Reviewers cannot do it; a volunteer of another slam cannot either.
        $this->expectReason('forbidden', fn () => $checkins->lateCheckin((int) $s['users'][0]['id'], null, $this->actorFor($s['users'][1])));
        $this->expectReason('forbidden', fn () => $checkins->lateCheckin((int) $s['users'][0]['id'], null, $other));

        $checkins->lateCheckin((int) $s['users'][0]['id'], 'train delayed', $vol);
        $checkins->lateCheckin((int) $s['users'][1]['id'], null, $this->admin);
        $this->expectReason('already_checked_in', fn () => $checkins->lateCheckin((int) $s['users'][0]['id'], null, $vol));
        $this->assertSame(2, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'checkin.late_override'"));
        $this->assertSame('train delayed', Db::value("SELECT reason FROM activity_log WHERE action = 'checkin.late_override' ORDER BY id LIMIT 1"));
        $this->assertSame('volunteer', Db::value('SELECT checked_in_by_type FROM checkins WHERE attendee_id = ?', [$s['users'][0]['id']]));

        // The phone claims the desk check-in on its first scan, even though the gate is closed.
        $who = $checkins->selfLookup($s['users'][0]['badge_id'], '7.7.7.7');
        $this->assertSame($s['users'][0]['name'], $who['name']);
        $claimed = $checkins->selfCheckin($s['users'][0]['badge_id'], '7.7.7.7');
        $this->assertFalse($claimed['resumed']);
        // A second phone is turned away.
        $this->expectReason('already_checked_in', fn () => $checkins->selfCheckin($s['users'][0]['badge_id'], '8.8.8.8'));
        // Someone never checked in at the desk still cannot self check-in.
        $this->expectReason('gate_closed', fn () => $checkins->selfCheckin($s['users'][2]['badge_id'], '9.9.9.9'));
    }

    public function testReviewSubmitRules(): void
    {
        $s    = $this->makeSlam(2);
        $id   = $s['id'];
        $user = $s['users'][0];
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($user);
        $actor = $this->actorFor($user);
        $item  = $s['items'][0];

        // Not open yet.
        $this->expectReason('no_open_window', fn () => service('reviews')->submit($user, $item, $this->answers($id, 'user', 7), null, $actor));
        $this->at('2026-11-01 04:45:00', $id);

        // Invalid answers: 0, 11, missing, extra, non-integer.
        $qs = service('questions')->active($id, 'user');
        $this->expectReason('invalid_score', fn () => service('reviews')->submit($user, $item, $this->answers($id, 'user', 0), null, $actor));
        $this->expectReason('invalid_score', fn () => service('reviews')->submit($user, $item, $this->answers($id, 'user', 11), null, $actor));
        $this->expectReason('invalid_score', fn () => service('reviews')->submit($user, $item, [['question_id' => $qs[0]['id'], 'score' => 5]], null, $actor));
        $judgeQ = service('questions')->active($id, 'judge')[0];
        $this->expectReason('invalid_score', fn () => service('reviews')->submit($user, $item, [...$this->answers($id, 'user', 5), ['question_id' => $judgeQ['id'], 'score' => 5]], null, $actor));
        $this->expectReason('invalid_score', fn () => service('reviews')->submit($user, $item, [['question_id' => $qs[0]['id'], 'score' => '7.5'], ['question_id' => $qs[1]['id'], 'score' => 7]], null, $actor));

        // Valid, then idempotent repeat.
        $r1 = service('reviews')->submit($user, $item, $this->answers($id, 'user', 8), 'Great', $actor);
        $this->assertFalse($r1['already']);
        $r2 = service('reviews')->submit($user, $item, $this->answers($id, 'user', 3), null, $actor);
        $this->assertTrue($r2['already']);
        $this->assertSame(8, (int) Db::value('SELECT score FROM review_answers WHERE review_id = ? LIMIT 1', [$r1['review_id']]));

        // Questions for users are now locked.
        $this->expectReason('questions_locked', fn () => service('questions')->create($id, 'user', ['text' => 'New question'], $this->admin));

        // After the deadline (even before the cron runs) submissions are rejected.
        $other = $s['users'][1];
        db_connect()->table('checkins')->insert(['attendee_id' => $other['id'], 'checked_in_at' => Clock::db(), 'open_marker' => 1, 'session_token_hash' => str_repeat('a', 64)]);
        Clock::set('2026-11-01 04:50:00');
        $this->expectReason('window_closed', fn () => service('reviews')->submit($other, $item, $this->answers($id, 'user', 6), null, $this->actorFor($other)));
    }

    public function testRevisionOnlyDuringEditWindowAndLockedAfter(): void
    {
        $s    = $this->makeSlam(1);
        $id   = $s['id'];
        $user = $s['users'][0];
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($user);
        $this->at('2026-11-01 04:45:00', $id);
        $actor = $this->actorFor($user);
        $rev   = service('reviews')->submit($user, $s['items'][0], $this->answers($id, 'user', 5), null, $actor);
        $this->expectReason('edit_not_allowed', fn () => service('reviews')->revise($user, $rev['review_id'], $this->answers($id, 'user', 9), null, $actor));

        $this->at('2026-11-01 04:50:00', $id); // window closes → reviews_complete → revision opens
        $this->assertSame('revision', $this->phase($id));
        $res = service('reviews')->revise($user, $rev['review_id'], $this->answers($id, 'user', 9), 'changed', $actor);
        $this->assertTrue($res['changed']);
        $this->assertSame(9, (int) Db::value('SELECT score FROM review_answers WHERE review_id = ? LIMIT 1', [$rev['review_id']]));
        $this->assertSame(3, (int) Db::value('SELECT COUNT(*) FROM review_revisions WHERE review_id = ?', [$rev['review_id']])); // 2 answers + comment
        $this->assertSame(1, (int) Db::value('SELECT edit_count FROM reviews WHERE id = ?', [$rev['review_id']]));

        $this->at('2026-11-01 05:00:00', $id); // Edit Close
        $this->expectReason('scores_locked', fn () => service('reviews')->revise($user, $rev['review_id'], $this->answers($id, 'user', 2), null, $actor));
    }

    public function testLiveStateShowsOnlyOwnQuestionSetAndEtagChangesOnSubmit(): void
    {
        $s    = $this->makeSlam(1);
        $id   = $s['id'];
        $user = $s['users'][0];
        $this->at('2026-11-01 03:30:00', $id);
        $this->checkIn($user);
        $this->at('2026-11-01 04:45:00', $id);
        $a     = ['id' => (int) $user['id'], 'slam_id' => $id, 'badge_id' => $user['badge_id'], 'name' => $user['name'], 'user_type' => 'user'];
        $state = service('liveState')->forReviewer($a);
        $this->assertSame('performance', $state['body']['current']['kind']);
        $this->assertCount(2, $state['body']['questions']);
        $this->assertFalse($state['body']['already_reviewed']);
        $this->assertSame('2026-11-01T04:50:00Z', $state['body']['current']['closes_at']);
        $this->assertArrayNotHasKey('mobile', $state['body']['me']);
        service('reviews')->submit($a, $s['items'][0], $this->answers($id, 'user', 6), null, $this->actorFor($user));
        $state2 = service('liveState')->forReviewer($a);
        $this->assertTrue($state2['body']['already_reviewed']);
        $this->assertNotSame($state['etag'], $state2['etag']);
    }
}
