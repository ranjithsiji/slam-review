<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Attendees\Services\AttendeeService;
use Modules\Attendees\Services\BadgeIdService;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class AttendeesTest extends SlamTestCase
{
    private function csvFile(string $content): string
    {
        $p = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($p, $content);

        return $p;
    }

    public function testBadgeIdFormatAndScanParsing(): void
    {
        $svc = new BadgeIdService();
        for ($i = 0; $i < 200; $i++) {
            $id = $svc->random();
            $this->assertTrue(BadgeIdService::isValidFormat($id), $id);
            $this->assertDoesNotMatchRegularExpression('/[IOQSZ]/', substr($id, 0, 2));
        }
        $this->assertFalse(BadgeIdService::isValidFormat('IO12345'));
        $this->assertSame('KR48213', BadgeIdService::fromScan('https://x.example/in/kr48213'));
        $this->assertSame('KR48213', BadgeIdService::fromScan(' kr 48213 '));
        $this->assertNull(BadgeIdService::fromScan('https://x.example/in/ZZ1'));
    }

    public function testMobileNormalisation(): void
    {
        foreach (['9876543210', '+91 98765 43210', '098765-43210', '91-9876543210', '(987) 654 3210'] as $m) {
            $this->assertSame('9876543210', AttendeeService::normaliseMobile($m), $m);
        }
        foreach (['5876543210', '98765', '+1 9876543210'] as $m) {
            $this->assertNull(AttendeeService::normaliseMobile($m), $m);
        }
    }

    public function testImportPreviewAndCommitWithBomSemicolonsDuplicatesAndMalayalam(): void
    {
        $s   = $this->makeSlam(1, 1, 0);
        $existingMobile = $s['users'][0]['mobile'];
        $csv = "\xEF\xBB\xBFName;Mobile;Email;Type;badge_id\n"
            . "ഫാത്തിമ നസ്രിൻ;+91 94470 12345;F@Example.com;;\n"
            . "Dup In File;9447012345;;user;\n"
            . "Bad Mobile;12345;;;\n"
            . "Existing;{$existingMobile};;judge;\n"
            . "Given Badge;9447099999;;judge;KR48213\n"
            . "Bad Badge;9447088888;;;IO12345\n";
        $pre = service('importer')->preview($s['id'], $this->csvFile($csv), 'people.csv', 'skip_existing', $this->admin);
        $this->assertSame(['new' => 2, 'duplicate' => 1, 'invalid' => 3], $pre['counts']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM attendees WHERE name = ?', ['Given Badge']));
        $this->assertStringContainsString('duplicate mobile in this file', service('importer')->errorsCsv($pre['batch_id']));

        $res = service('importer')->commit($pre['batch_id'], $this->admin);
        $this->assertSame(2, $res['created']);
        $this->assertSame(1, $res['skipped']);
        $this->assertSame('ഫാത്തിമ നസ്രിൻ', Db::value('SELECT name FROM attendees WHERE mobile = ?', ['9447012345']));
        $this->assertSame('f@example.com', Db::value('SELECT email FROM attendees WHERE mobile = ?', ['9447012345']));
        $this->assertSame('KR48213', Db::value('SELECT badge_id FROM attendees WHERE mobile = ?', ['9447099999']));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM import_batch_rows WHERE batch_id = ?', [$pre['batch_id']]));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM checkins'), 'import never checks anyone in');

        // Same file again: warned, needs confirmation.
        $pre2 = service('importer')->preview($s['id'], $this->csvFile($csv), 'people.csv', 'update_existing', $this->admin);
        $this->assertNotNull($pre2['already_committed_batch']);
        try {
            service('importer')->commit($pre2['batch_id'], $this->admin);
            $this->fail('expected reimport warning');
        } catch (DomainException $e) {
            $this->assertSame('reimport', $e->reason);
        }
    }

    public function testUpdateExistingNeverChangesBadgeId(): void
    {
        $s     = $this->makeSlam(1, 1, 0);
        $u     = $s['users'][0];
        $csv   = "name,mobile,type,badge_id\nRenamed Person,{$u['mobile']},judge,KR11111\n";
        $pre   = service('importer')->preview($s['id'], $this->csvFile($csv), 'x.csv', 'update_existing', $this->admin);
        service('importer')->commit($pre['batch_id'], $this->admin);
        $row = Db::row('SELECT * FROM attendees WHERE id = ?', [$u['id']]);
        $this->assertSame('Renamed Person', $row['name']);
        $this->assertSame('judge', $row['user_type']);
        $this->assertSame($u['badge_id'], $row['badge_id']);
    }

    public function testRowLimitRejected(): void
    {
        $s   = $this->makeSlam(1, 1, 0);
        $csv = "name,mobile\n" . str_repeat("Some Name,9876543210\n", 5001);
        $this->expectException(DomainException::class);
        service('importer')->preview($s['id'], $this->csvFile($csv), 'big.csv', 'skip_existing', $this->admin);
    }

    public function testCsvSafeStripsFormulaPrefixes(): void
    {
        $this->assertSame('SUM(A1)', AttendeeService::csvSafe('=SUM(A1)'));
        $this->assertSame('cmd', AttendeeService::csvSafe('@+-cmd'));
        $this->assertSame('Anu', AttendeeService::csvSafe('Anu'));
    }

    public function testQrDecodesToExactUrlAndReissueEndsSession(): void
    {
        $qr  = service('qr');
        $url = $qr->badgeUrl('KR48213');
        $this->assertSame($url, $qr->decodePng($qr->png($url, 6)));

        $s = $this->makeSlam(1, 1, 0);
        $this->at('2026-11-01 03:30:00', $s['id']);
        $sess = $this->checkIn($s['users'][0]);
        $new  = service('attendees')->reissueId((int) $s['users'][0]['id'], 'Badge lost', $this->admin);
        $this->assertNotSame($s['users'][0]['badge_id'], $new);
        $this->assertNull(service('checkins')->validateSession($sess));
    }

    public function testBadgePdfRendersMalayalam(): void
    {
        $s   = $this->makeSlam(1, 0, 0);
        $pdf = service('badgePdf')->badges(service('slams')->find($s['id']), [['name' => 'ഫാത്തിമ നസ്രിൻ', 'badge_id' => 'KR48213', 'user_type' => 'judge']]);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('Manjari', $pdf);
    }

    public function testStaffLoginLockout(): void
    {
        $this->expectExceptionMessage('Too many attempts');
        for ($i = 0; $i < 6; $i++) {
            try {
                service('auth')->login('admin', 'nobody', 'wrong-password', '9.9.9.9');
            } catch (DomainException $e) {
                if ($e->reason === 'locked_out') {
                    throw $e;
                }
                $this->assertSame('Invalid username or password.', $e->getMessage());
            }
        }
    }
}
