<?php

declare(strict_types=1);

namespace Modules\Attendees\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Db;

/** Imports an attendee CSV in one go (nothing is saved if any row is invalid) and writes the badge list. */
class ImportAttendees extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:import-attendees';
    protected $description = 'Import attendees from a CSV into a slam and write their badge IDs and check-in URLs to a file.';
    protected $usage       = 'slam:import-attendees <slam-slug> <in.csv> <badges-out.csv>';

    public function run(array $params): void
    {
        [$slug, $in, $out] = $params + [null, null, null];
        $slam = $slug !== null ? service('slams')->findBySlug((string) $slug) : null;
        if ($slam === null || $in === null || $out === null) {
            CLI::error('Usage: ' . $this->usage);

            return;
        }
        $admin = Db::row("SELECT id, username FROM admins WHERE role = 'admin' ORDER BY id LIMIT 1");
        if ($admin === null) {
            CLI::error('No admin account to record the import against.');

            return;
        }
        $actor    = new Actor('admin', (int) $admin['id'], $admin['username']);
        $slamId   = (int) $slam['id'];
        $importer = service('importer');

        try {
            $pre = $importer->preview($slamId, (string) $in, basename((string) $in), 'skip_existing', $actor);
            CLI::write('Checked: ' . json_encode($pre['counts']));
            if ($pre['counts']['invalid'] > 0) {
                foreach (Db::rows("SELECT line_no, error FROM import_batch_rows WHERE batch_id = ? AND status = 'invalid'", [$pre['batch_id']]) as $r) {
                    CLI::error("line {$r['line_no']}: {$r['error']}");
                }
                $importer->cancel($pre['batch_id'], $actor);
                CLI::error('Nothing was imported.');

                return;
            }
            CLI::write('Imported: ' . json_encode($importer->commit($pre['batch_id'], $actor)));
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());

            return;
        }

        $qr = service('qr');
        $fh = fopen((string) $out, 'w');
        fputcsv($fh, ['name', 'mobile', 'email', 'category', 'badge_id', 'checkin_url'], ',', '"', '');
        $rows = Db::rows("SELECT id, name, mobile, email, category, badge_id FROM attendees WHERE slam_id = ? AND import_batch_id = ? ORDER BY name", [$slamId, $pre['batch_id']]);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['name'], $r['mobile'], $r['email'], $r['category'], $r['badge_id'], $qr->badgeUrl($r['badge_id'])], ',', '"', '');
        }
        fclose($fh);
        service('attendees')->markPrinted(array_map('intval', array_column($rows, 'id')));
        service('activityLog')->log($actor, 'badge.exported', ['slam_id' => $slamId, 'meta' => ['format' => 'cli', 'count' => count($rows)]]);
        CLI::write(count($rows) . ' badges written to ' . $out, 'green');
    }
}
