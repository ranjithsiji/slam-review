<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/** Creates published slams from a programme file: talks and breaks become the timeline, the rest is display-only. */
class ImportProgramme extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:import-programme';
    protected $description = 'Create slams, presenters and schedules from a programme JSON file.';
    protected $usage       = 'slam:import-programme <file.json> <photos-folder>';

    public function run(array $params): void
    {
        $doc = json_decode((string) @file_get_contents((string) ($params[0] ?? '')), true);
        if (! is_array($doc) || ! isset($doc['slams'])) {
            CLI::error('Cannot read the programme file.');

            return;
        }
        foreach ($doc['slams'] as $slam) {
            try {
                $this->importSlam($slam, rtrim((string) ($params[1] ?? ''), '/'));
            } catch (\Throwable $e) {
                $details = method_exists($e, 'getDetails') ? json_encode($e->getDetails(), JSON_UNESCAPED_UNICODE) : '';
                CLI::error("{$slam['key']}: {$e->getMessage()} {$details}");

                return;
            }
        }
    }

    private function importSlam(array $s, string $photos): void
    {
        $slug = (string) $s['key'];
        if (service('slams')->findBySlug($slug) !== null) {
            CLI::write("{$slug}: already exists, left unchanged", 'yellow');

            return;
        }
        $seq   = $s['seq'];
        $isT   = static fn (array $x): bool => $x['t'] === 'talk';
        $first = null;
        $last  = null;
        foreach ($seq as $i => $x) {
            if ($isT($x)) {
                $first ??= $i;
                $last = $i;
            }
        }
        $at     = static fn (string $time): string => Clock::fromLocal($s['date'], $time);
        $timed  = array_values(array_filter($seq, static fn (array $x): bool => isset($x['start'])));
        $gate   = $timed[0]['start'];
        $finish = $timed[array_key_last($timed)]['end'];
        $admin  = Db::row("SELECT id, username FROM admins WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
        if ($admin === null) {
            throw new \RuntimeException('Create an admin first (php spark slam:create-admin).');
        }
        $actor  = new Actor('admin', (int) $admin['id'], $admin['username']);
        $id     = service('slams')->create([
            'name' => 'Science Slam ' . ucfirst($slug), 'type' => 'regional', 'slug' => $slug, 'venue_name' => $s['venue'],
            'city' => ucfirst($slug), 'district' => ucfirst($slug), 'state' => 'Kerala',
            'opens_at' => Clock::addMinutes($at($gate), -30), 'checkin_opens_at' => $at($gate), 'checkin_closes_at' => $at($seq[$first]['start']),
            'reviews_start_at' => $at($seq[$first]['start']), 'closes_at' => $at($finish),
            'default_review_minutes' => 10, 'changeover_minutes' => 2, 'edit_window_minutes' => 10, 'close_grace_minutes' => 5,
            'default_tea_break_minutes' => 16, 'default_lunch_break_minutes' => 59,
        ], $actor);

        try {
            $this->fill($id, $seq, $first, $last, $at, $photos, $actor);
        } catch (\Throwable $e) {
            service('slams')->delete($id, $actor); // Never leave a half-built slam behind.

            throw $e;
        }
        CLI::write("{$slug}: created with " . count(array_filter($seq, $isT)) . ' talks', 'green');
    }

    private function fill(int $id, array $seq, int $first, int $last, callable $at, string $photos, Actor $actor): void
    {
        $isT   = static fn (array $x): bool => $x['t'] === 'talk';
        $items = [];
        foreach ($seq as $i => $x) {
            if ($i < $first || $i > $last || $x['t'] === 'heading') {
                continue;
            }
            $minutes = static fn (array $r): int => (int) ((strtotime($r['end']) - strtotime($r['start'])) / 60);
            if ($isT($x)) {
                $photo = ! empty($x['photo']) && is_file("{$photos}/{$x['photo']}") ? "{$photos}/{$x['photo']}" : null;
                $ids   = service('presenters')->addToSlam($id, [
                    'name' => $x['name'], 'designation_or_institution' => $x['inst'] ?? '', 'photo_alt' => $x['name'],
                    'topic' => $x['topic'], 'abstract' => $x['abstract'] ?? '',
                ], $actor, $photo, false);
                $items[] = ['item_type' => 'performance', 'performance_id' => $ids['performance_id'], 'planned_start_at' => $at($x['start']), 'duration_minutes' => 10];
            } else {
                $items[] = ['item_type' => 'break', 'break_type' => $x['break_type'] ?? 'other', 'label' => $x['label'], 'planned_start_at' => $at($x['start']), 'duration_minutes' => $minutes($x)];
            }
        }
        service('schedule')->saveDraft($id, $items, (int) service('slams')->find($id)['schedule_version'], $actor, 'Imported programme');

        $db = db_connect();
        foreach ($seq as $i => $x) {
            // The heading right before the first talk is a session, which the public schedule derives by itself.
            if (($i >= $first && $i <= $last) || $i === $first - 1) {
                continue;
            }
            $heading = $x['t'] === 'heading';
            $db->table('programme_extras')->insert([
                'slam_id' => $id, 'placement' => $i < $first ? 'before' : 'after', 'position' => $i,
                'item_type' => $heading ? 'heading' : ($x['t'] === 'break' ? 'break' : 'item'), 'break_type' => $x['break_type'] ?? null,
                'label' => $heading ? $x['title'] : $x['label'], 'note' => $x['note'] ?? null,
                'starts_at' => $heading ? null : $at($x['start']), 'ends_at' => $heading ? null : $at($x['end']),
            ]);
        }
        service('slamPhase')->markReady($id, $actor);
        service('slams')->setPublished($id, true, $actor);
    }
}
