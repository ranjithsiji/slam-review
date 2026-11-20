<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Sets an unopened slam's timetable to the published programme (from exclude/star/site-programme.py):
 * talk times by presenter name, break times and lengths, the display-only items, and the closing time.
 * A talk missing from the programme is taken out (performance kept, inactive). Everything is checked first.
 */
class SyncProgramme extends BaseCommand
{
    protected $group = 'Science Slam';
    protected $name = 'slam:sync-programme';
    protected $usage = 'slam:sync-programme <slug> <programme.json> [--dry-run]';
    protected $description = 'Set an unopened slam\'s timetable to the published programme.';

    public function run(array $params): void
    {
        [$slug, $file] = $params + [null, null];
        $slam = service('slams')->findBySlug((string) $slug);
        $doc  = json_decode((string) @file_get_contents((string) $file), true);
        if ($slam === null || ! is_array($doc)) { CLI::error('Usage: ' . $this->usage); return; }
        if ($slam['status'] !== 'scheduled') { CLI::error("{$slug} is {$slam['status']}; only an unopened slam is changed."); return; }
        $id    = (int) $slam['id'];
        $talks = array_column($doc['talks'], null, 'name');
        $brks  = array_column($doc['breaks'], null, 'label');
        $mins  = static fn (array $r): int => (int) round((strtotime($r['end']) - strtotime($r['start'])) / 60);
        $rows  = Db::rows('SELECT si.*, pr.name AS presenter FROM schedule_items si LEFT JOIN performances p ON p.id = si.performance_id LEFT JOIN presenters pr ON pr.id = p.presenter_id WHERE si.slam_id = ? ORDER BY si.position, si.id', [$id]);
        $draft = service('schedule');
        $items = []; $drop = []; $seen = []; $problems = []; $changes = [];
        foreach ($rows as $r) {
            if ($r['item_type'] === 'performance') {
                if (! isset($talks[$r['presenter']])) {
                    if ($r['status'] !== 'skipped') { $drop[] = $r; $changes[] = "remove {$r['presenter']}"; }
                    continue;
                }
                $t = $talks[$r['presenter']]; $seen[$r['presenter']] = true;
                if ($t['start'] !== $r['planned_start_at']) $changes[] = "{$r['presenter']}: {$r['planned_start_at']} -> {$t['start']}";
                $r['planned_start_at'] = $t['start'];
            } else {
                $b = $brks[$r['label']] ?? null;
                if ($b === null) { $problems[] = "break not on the programme: {$r['label']}"; continue; }
                if ($b['start'] !== $r['planned_start_at'] || $mins($b) !== (int) $r['duration_minutes']) $changes[] = "{$r['label']}: {$r['planned_start_at']} {$r['duration_minutes']}' -> {$b['start']} {$mins($b)}'";
                $r['planned_start_at'] = $b['start']; $r['duration_minutes'] = $mins($b);
            }
            $items[] = $r;
        }
        foreach (array_keys($talks) as $n) if (! isset($seen[$n])) $problems[] = "talk on the programme but not in this slam: {$n}";
        foreach ($drop as $d) if ((int) Db::value('SELECT COUNT(*) FROM reviews WHERE schedule_item_id = ?', [$d['id']]) > 0) $problems[] = "{$d['presenter']} has reviews";
        $extras = Db::rows('SELECT * FROM programme_extras WHERE slam_id = ? AND starts_at IS NOT NULL', [$id]);
        $byLabel = [];
        foreach ($doc['extras'] as $e) $byLabel[$e['label']] = $e;
        foreach ($doc['breaks'] as $b) $byLabel[$b['label']] ??= $b; // a display-only break (the evening tea) is listed as a break
        foreach ($extras as $e) {
            if (! isset($byLabel[$e['label']])) { $problems[] = "programme item not found: {$e['label']}"; continue; }
            if ($byLabel[$e['label']]['start'] !== $e['starts_at'] || $byLabel[$e['label']]['end'] !== $e['ends_at']) $changes[] = "{$e['label']}: {$e['starts_at']} -> {$byLabel[$e['label']]['start']}";
        }
        $ends   = array_merge(array_column($doc['talks'], 'end'), array_column($doc['breaks'], 'end'), array_column($doc['extras'], 'end'));
        $closes = max($ends) . '';
        if ($closes !== $slam['closes_at']) $changes[] = "closes_at: {$slam['closes_at']} -> {$closes}";
        if ($problems !== []) { foreach ($problems as $p) CLI::error($p); CLI::error('Nothing changed.'); return; }
        foreach ($changes as $c) CLI::write('  ' . $c);
        if ($changes === []) { CLI::write('Already matches the programme.', 'green'); return; }
        if (array_key_exists('dry-run', $params) || in_array('--dry-run', $params, true) || CLI::getOption('dry-run') !== null) { CLI::write('Dry run: nothing changed.', 'yellow'); return; }

        $admin = Db::row("SELECT id, username FROM admins WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
        $actor = new Actor('admin', (int) $admin['id'], $admin['username']);
        $note  = 'Matched to the published programme';
        usort($items, static fn ($a, $b) => strcmp($a['planned_start_at'], $b['planned_start_at']));
        $draft->saveDraft($id, $draft->toDraft($items), (int) $slam['schedule_version'], $actor, $note);
        foreach ($drop as $d) {
            $p = Db::row('SELECT * FROM performances WHERE id = ?', [$d['performance_id']]);
            service('presenters')->updatePerformance((int) $p['id'], ['topic' => $p['topic'], 'abstract' => $p['abstract'], 'is_active' => 0], $actor);
        }
        $db = db_connect();
        foreach ($extras as $e) {
            $db->table('programme_extras')->where('id', $e['id'])->update(['starts_at' => $byLabel[$e['label']]['start'], 'ends_at' => $byLabel[$e['label']]['end']]);
        }
        if ($closes !== $slam['closes_at']) {
            $db->table('slams')->where('id', $id)->update(['closes_at' => $closes, 'updated_at' => Clock::db()]);
            service('activityLog')->log($actor, 'slam.updated', ['slam_id' => $id, 'target_type' => 'slam', 'target_id' => $id, 'old' => ['closes_at' => $slam['closes_at']], 'new' => ['closes_at' => $closes], 'reason' => $note]);
        }
        service('publicCache')->purge($id);
        CLI::write('Done.', 'green');
    }
}
