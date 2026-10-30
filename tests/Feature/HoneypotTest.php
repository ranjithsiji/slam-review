<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Core\Filters\HoneypotFilter;
use Tests\Support\SlamTestCase;

final class HoneypotTest extends SlamTestCase
{
    public function testVerdicts(): void
    {
        $now = 1_800_000_000;
        $old = HoneypotFilter::stamp($now - 30);
        $this->assertNull(HoneypotFilter::verdict('', $old, $now));
        $this->assertSame('trap_filled', HoneypotFilter::verdict('http://spam.example', $old, $now));
        $this->assertSame('no_stamp', HoneypotFilter::verdict('', '', $now));
        $this->assertSame('no_stamp', HoneypotFilter::verdict('', ($now - 30) . '.deadbeef', $now));
        // A stamp cannot be forged by changing the time.
        [, $sig] = explode('.', $old);
        $this->assertSame('no_stamp', HoneypotFilter::verdict('', ($now - 90) . '.' . $sig, $now));
        $this->assertSame('too_fast', HoneypotFilter::verdict('', HoneypotFilter::stamp($now - 1), $now));
        $this->assertNull(HoneypotFilter::verdict('', HoneypotFilter::stamp($now - HoneypotFilter::MIN_SECONDS), $now));
    }

    public function testFormFieldsAreInvisibleToPeopleAndSigned(): void
    {
        helper('ui');
        $html = honeypot_fields();
        $this->assertStringContainsString('name="hp_website"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        preg_match('/name="hp_ts" value="([^"]+)"/', $html, $m);
        $this->assertNull(HoneypotFilter::verdict('', $m[1], time() + 5));
    }
}
