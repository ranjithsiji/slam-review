<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\PublicSite\Services\PublicScheduleService;
use Tests\Support\SlamTestCase;

/** Sessions on the public schedule are derived from the talks and breaks. */
final class PublicProgrammeTest extends SlamTestCase
{
    private int $id = 0;

    /** Times are IST, stored as UTC ISO like the public document. */
    private function slot(string $istTime, int $minutes, string $kind = 'performance', ?string $breakType = null): array
    {
        return [
            'id' => ++$this->id, 'kind' => $kind, 'break_type' => $breakType, 'label' => $kind === 'break' ? 'Item' : null,
            'presenter_name' => 'Name', 'designation' => null, 'topic' => 'Topic', 'abstract' => null, 'profile' => null,
            'thumb' => null, 'photo' => null, 'status' => 'upcoming', 'duration_minutes' => $minutes,
            'planned_start_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime("2026-11-07 {$istTime} Asia/Kolkata")),
        ];
    }

    public function testGroupsOpeningSessionsBreaksAndClosing(): void
    {
        $sections = (new PublicScheduleService())->programme([
            $this->slot('09:00', 60, 'break', 'other'),
            $this->slot('10:00', 10), $this->slot('10:12', 10),
            $this->slot('10:24', 15, 'break', 'tea'),
            $this->slot('10:39', 10), $this->slot('10:51', 10),
            $this->slot('11:03', 60, 'break', 'lunch'),
            $this->slot('14:00', 10), $this->slot('14:12', 10),
            $this->slot('14:24', 30, 'break', 'lunch'),
            $this->slot('14:54', 20, 'break', 'other'),
        ]);

        $this->assertSame(['opening', 'session', 'break', 'session', 'break', 'session', 'break', 'closing'], array_column($sections, 'kind'));
        $this->assertSame('തുടക്കം (Opening)', $sections[0]['title']);
        $this->assertStringContainsString('(Morning Session 1)', $sections[1]['title']);
        $this->assertStringContainsString('(Morning Session 2)', $sections[3]['title']);
        $this->assertStringContainsString('(Afternoon Session)', $sections[5]['title']);
        $this->assertSame('സമാപന സമ്മേളനം & ഫലപ്രഖ്യാപനം (Valedictory Session)', $sections[7]['title']);
        $this->assertSame([1, 2], array_column($sections[1]['rows'], 'number'));
        $this->assertSame([3, 4], array_column($sections[3]['rows'], 'number'));
    }

    public function testSessionNoteAndEndTimesIncludeTheChangeover(): void
    {
        $sections = (new PublicScheduleService())->programme([$this->slot('10:00', 10), $this->slot('10:12', 10), $this->slot('10:24', 10)]);

        $this->assertStringContainsString('10 മിനിറ്റ് + 2 മുന്നൊരുക്കം = 12 മിനിറ്റ്', (string) $sections[0]['note']);
        $this->assertSame(gmdate('Y-m-d H:i:s', strtotime('2026-11-07 10:12 Asia/Kolkata')), $sections[0]['rows'][0]['end_at']);
    }

    public function testMixedTimingsHideTheNoteAndAnEmptyScheduleIsEmpty(): void
    {
        $svc = new PublicScheduleService();
        $this->assertNull($svc->programme([$this->slot('10:00', 10), $this->slot('10:12', 5), $this->slot('10:20', 5)])[0]['note']);
        $this->assertSame([], $svc->programme([]));
    }

    public function testDisplayOnlyExtrasGoAroundTheTalks(): void
    {
        $utc = static fn (string $t): string => gmdate('Y-m-d H:i:s', strtotime("2026-11-07 {$t} Asia/Kolkata"));
        $sections = (new PublicScheduleService())->programme([$this->slot('10:00', 10), $this->slot('10:12', 10)], [
            ['placement' => 'before', 'item_type' => 'heading', 'break_type' => null, 'label' => 'Opening', 'note' => null, 'starts_at' => null, 'ends_at' => null],
            ['placement' => 'before', 'item_type' => 'item', 'break_type' => null, 'label' => 'Registration', 'note' => null, 'starts_at' => $utc('09:00'), 'ends_at' => $utc('10:00')],
            ['placement' => 'after', 'item_type' => 'break', 'break_type' => 'tea', 'label' => 'Tea', 'note' => null, 'starts_at' => $utc('10:24'), 'ends_at' => $utc('10:36')],
            ['placement' => 'after', 'item_type' => 'heading', 'break_type' => null, 'label' => 'Valedictory', 'note' => null, 'starts_at' => null, 'ends_at' => null],
            ['placement' => 'after', 'item_type' => 'item', 'break_type' => null, 'label' => 'Results', 'note' => null, 'starts_at' => $utc('10:36'), 'ends_at' => $utc('11:00')],
        ]);

        $this->assertSame(['extra', 'session', 'break', 'extra'], array_column($sections, 'kind'));
        $this->assertSame($utc('10:24'), $sections[1]['rows'][1]['end_at']);
        $this->assertSame($utc('09:00'), $sections[0]['start_at']);
        $this->assertArrayNotHasKey('id', $sections[0]['rows'][0]);
    }
}
