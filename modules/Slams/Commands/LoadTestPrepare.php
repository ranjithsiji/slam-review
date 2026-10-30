<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Creates a throw-away "Load test" slam with N attendees and the gate open, and
 * writes their badge IDs to tests/load/badges.json for tests/load/reviewers.js (k6).
 * Never run this against production data.
 *
 *   php spark slam:loadtest-prepare 1000
 */
class LoadTestPrepare extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:loadtest-prepare';
    protected $description = 'Create a load-test slam with N attendees (gate open) and write tests/load/badges.json.';
    protected $usage       = 'slam:loadtest-prepare [count]';

    public function run(array $params): void
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Refusing to run in production.');

            return;
        }
        $n     = max(1, (int) ($params[0] ?? 1000));
        $admin = Db::row('SELECT id, username FROM admins ORDER BY id LIMIT 1');
        if ($admin === null) {
            CLI::error('Create an admin first (php spark slam:create-admin).');

            return;
        }
        $actor = new Actor('admin', (int) $admin['id'], $admin['username']);
        $now   = Clock::now();
        $id    = service('slams')->create([
            'name' => 'Load test ' . $now->format('His'), 'type' => 'regional', 'slug' => 'load-test-' . $now->format('YmdHis'),
            'venue_name' => 'Load test', 'opens_at' => Clock::db($now->modify('-2 minutes')), 'checkin_opens_at' => Clock::db($now->modify('-1 minute')),
            'checkin_closes_at' => Clock::db($now->modify('+3 hours')), 'closes_at' => Clock::db($now->modify('+6 hours')),
            'default_review_minutes' => 5, 'changeover_minutes' => 1, 'edit_window_minutes' => 5,
        ], $actor);
        for ($i = 1; $i <= 8; $i++) {
            service('presenters')->addToSlam($id, ['name' => "Load presenter {$i}", 'topic' => "Topic {$i}", 'planned_start_at' => Clock::db($now->modify('+' . (180 + $i * 7) . ' minutes'))], $actor);
        }
        $db     = db_connect();
        $badges = [];
        $reserved = [];
        for ($i = 0; $i < $n; $i++) {
            $badge = service('badgeIds')->generate($reserved);
            $reserved[$badge] = true;
            $db->table('attendees')->insert([
                'slam_id' => $id, 'badge_id' => $badge, 'name' => "Load phone {$i}", 'mobile' => (string) (6000000000 + $id * 100000 + $i),
                'user_type' => $i % 20 === 0 ? 'judge' : 'user', 'is_active' => 1, 'created_at' => Clock::db(), 'updated_at' => Clock::db(),
            ]);
            $badges[] = $badge;
        }
        service('slamPhase')->markReady($id, $actor);
        service('slamPhase')->tick($id, true);
        $file = ROOTPATH . 'tests/load/badges.json';
        file_put_contents($file, json_encode(['slam_id' => $id, 'badges' => $badges]));
        CLI::write("Slam #{$id} ready (" . service('slams')->find($id)['status'] . "), {$n} badges written to tests/load/badges.json", 'green');
        CLI::write('Open a review window from the control panel during the run to exercise review submissions.');
    }
}
