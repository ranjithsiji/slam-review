<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\DomainException;
use Tests\Support\SlamTestCase;

final class SettingsTest extends SlamTestCase
{
    public function testDefaultsAreIndiaAnd24Hour(): void
    {
        $this->assertSame(['timezone' => 'Asia/Kolkata', 'time_format' => '24h'], service('appSettings')->all());
        $this->assertSame('IST', Clock::tzLabel());
        $this->assertSame('01 Nov 2026, 08:30', Clock::local('2026-11-01 03:00:00'));
        $this->assertSame('2026-11-01 03:00:00', Clock::fromLocal('2026-11-01', '08:30'));
        $this->assertSame('2026-11-01 03:00:00', Clock::fromLocalInput('2026-11-01T08:30'));
    }

    public function testChangingTimezoneAndFormatChangesInputAndDisplayOnly(): void
    {
        $s = $this->makeSlam(1, 1, 0);
        service('appSettings')->update(['timezone' => 'Europe/London', 'time_format' => '12h'], $this->admin);

        $this->assertSame('Europe/London', Clock::tz());
        // The stored moment is unchanged; only its local reading differs.
        $this->assertSame('2026-11-01 03:00:00', Db::value('SELECT opens_at FROM slams WHERE id = ?', [$s['id']]));
        $this->assertSame('01 Nov 2026, 3:00 AM', Clock::local('2026-11-01 03:00:00'));
        $this->assertSame('3:00:05 AM', Clock::local('2026-11-01 03:00:05', 'H:i:s'));
        // Form values never use the 12-hour style.
        helper('ui');
        $this->assertSame('2026-11-01T03:00', local_input('2026-11-01 03:00:00'));
        // Summer time: 09:00 in London in July is 08:00 UTC.
        $this->assertSame('2026-07-01 08:00:00', Clock::fromLocal('2026-07-01', '09:00'));
        $this->assertSame('BST', Clock::tzLabel('2026-07-01 08:00:00'));
        $this->assertSame('GMT', Clock::tzLabel('2026-11-01 03:00:00'));

        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'settings.updated'"));
        // A fresh service instance reads the stored values back.
        $this->assertSame('Europe/London', (new \Modules\Core\Services\SettingsService())->get('timezone'));
    }

    public function testInvalidValuesAndNonAdminsAreRejected(): void
    {
        foreach ([['timezone' => 'Mars/Olympus', 'time_format' => '24h'], ['timezone' => 'Asia/Kolkata', 'time_format' => '36h']] as $bad) {
            try {
                service('appSettings')->update($bad, $this->admin);
                $this->fail('Expected validation error');
            } catch (DomainException $e) {
                $this->assertSame('validation', $e->reason);
            }
        }
        $this->expectException(DomainException::class);
        service('appSettings')->update(['timezone' => 'UTC', 'time_format' => '24h'], new Actor('volunteer', 1, 'v', 1));
    }
}
