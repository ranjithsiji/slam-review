<?php

declare(strict_types=1);

namespace Modules\Slams\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Attendees\Services\AttendeeService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Creates a separate slam for a rehearsal: test presenters, judges spread over the four categories, experts,
 * and its own operator and volunteer logins. Nothing in other slams is touched. Times can be changed in the admin.
 *
 *   php spark slam:trial-create "Test Slam Kollam" --date 2026-11-14 --venue Kollam --judges 40 --experts 5 --presenters 8
 *
 * Badge IDs and the new logins are written to writable/trial-<slug>.csv (not reachable from the web).
 */
class TrialCreate extends BaseCommand
{
    protected $group       = 'Science Slam';
    protected $name        = 'slam:trial-create';
    protected $description = 'Create a rehearsal slam with test presenters, judges, experts and its own operator/volunteer logins.';
    protected $usage       = 'slam:trial-create "Name" [--date YYYY-MM-DD] [--venue Place] [--judges 40] [--experts 5] [--presenters 8]';

    public function run(array $params): void
    {
        $name = trim((string) ($params[0] ?? 'Test Slam'));
        $tz   = new \DateTimeZone(Clock::tz());
        $day  = (string) (CLI::getOption('date') ?? Clock::now()->setTimezone($tz)->modify('+1 day')->format('Y-m-d'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            CLI::error('--date must be YYYY-MM-DD');

            return;
        }
        $venue      = (string) (CLI::getOption('venue') ?? 'Test venue');
        $nJudges    = max(4, (int) (CLI::getOption('judges') ?? 40));
        $nExperts   = max(1, (int) (CLI::getOption('experts') ?? 5));
        $nPresent   = max(1, (int) (CLI::getOption('presenters') ?? 8));
        $slug       = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') . '-' . str_replace('-', '', $day);

        if (Db::value('SELECT id FROM slams WHERE slug = ?', [$slug]) !== null) {
            CLI::error("A slam with the slug {$slug} already exists. Use another name or date.");

            return;
        }
        $admin = Db::row("SELECT id, username FROM admins WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")
            ?? Db::row('SELECT id, username FROM admins ORDER BY id LIMIT 1');
        if ($admin === null) {
            CLI::error('Create an admin first (php spark slam:create-admin).');

            return;
        }
        $actor = new Actor('admin', (int) $admin['id'], $admin['username']);

        $id = service('slams')->create([
            'name' => $name, 'type' => 'regional', 'slug' => $slug,
            'description' => 'Rehearsal slam with test data. Safe to delete afterwards (php spark slam:delete).',
            'venue_name' => $venue, 'city' => $venue, 'district' => $venue, 'state' => 'Kerala',
            'contact_name' => 'Organising Desk',
            'opens_at' => Clock::fromLocal($day, '09:00'), 'checkin_opens_at' => Clock::fromLocal($day, '09:15'),
            'checkin_closes_at' => Clock::fromLocal($day, '10:15'), 'reviews_start_at' => Clock::fromLocal($day, '10:30'),
            'closes_at' => Clock::fromLocal($day, '17:00'),
            'default_review_minutes' => 3, 'changeover_minutes' => 2, 'edit_window_minutes' => 10, 'close_grace_minutes' => 5,
            'default_tea_break_minutes' => 15, 'default_lunch_break_minutes' => 45,
        ], $actor);

        $topics = [
            ['Why monsoon clouds forget to rain', 'IISER Thiruvananthapuram'], ['The secret life of mangrove roots', 'CUSAT'],
            ['കായലിലെ സൂക്ഷ്മജീവികൾ', 'University of Kerala'], ['Teaching robots to pluck coconuts', 'NIT Calicut'],
            ['Seeds that travel by elephant', 'KFRI Peechi'], ['Cashew shells as battery carbon', 'TKM College of Engineering, Kollam'],
            ['Listening to the Ashtamudi lake', 'Fatima Mata National College, Kollam'], ['Black sand and rare earths', 'IREL Chavara'],
            ['Fish that change sex', 'Kerala University of Fisheries and Ocean Studies'], ['Why coir does not rot', 'Central Coir Research Institute'],
        ];
        for ($i = 0; $i < $nPresent; $i++) {
            [$topic, $inst] = $topics[$i % count($topics)];
            $pName = 'Test Presenter ' . ($i + 1);
            service('presenters')->addToSlam($id, [
                'name' => $pName, 'designation_or_institution' => $inst, 'profile' => "{$pName} is a researcher at {$inst}.",
                'topic' => $topic, 'abstract' => 'Test talk for the rehearsal.',
            ], $actor);
        }

        $cats  = array_keys(AttendeeService::CATEGORIES);
        $first = ['Arjun', 'Meera', 'Nikhil', 'Sneha', 'Vishnu', 'Aparna', 'Gokul', 'Divya', 'Hari', 'Keerthana'];
        $rows  = [];
        $base  = 9100000000 + ((int) $id % 90) * 100000;
        for ($i = 0; $i < $nJudges + $nExperts; $i++) {
            $expert = $i >= $nJudges;
            $pName  = $expert ? 'Test Expert ' . ($i - $nJudges + 1) : 'Test ' . $first[$i % 10] . ' ' . ($i + 1);
            $mobile = (string) ($base + $i);
            $aid    = service('attendees')->create($id, [
                'name' => $pName, 'mobile' => $mobile, 'user_type' => $expert ? 'judge' : 'user',
                'category' => $expert ? '' : $cats[$i % count($cats)],
            ], $actor);
            $a      = Db::row('SELECT badge_id, category FROM attendees WHERE id = ?', [$aid]);
            $rows[] = [$expert ? 'expert' : 'judge', $pName, $mobile, $a['badge_id'], (string) $a['category']];
        }

        $suffix = substr($slug, 0, 12);
        $logins = [];
        $opUser = 'op-' . $suffix;
        $opPass = $this->password();
        service('auth')->saveAdmin(null, ['username' => $opUser, 'name' => 'Trial Operator', 'role' => 'operator', 'slam_id' => $id, 'password' => $opPass, 'is_active' => 1], $actor);
        $logins[] = ['operator', $opUser, $opPass];
        $voUser = 'vol-' . $suffix;
        $voPass = $this->password();
        service('auth')->saveVolunteer(null, ['username' => $voUser, 'name' => 'Trial Volunteer', 'slam_id' => $id, 'password' => $voPass, 'is_active' => 1], $actor);
        $logins[] = ['volunteer', $voUser, $voPass];

        if (service('slams')->find($id)['status'] === 'draft') {
            service('slamPhase')->markReady($id, $actor);
        }

        $file = WRITEPATH . "trial-{$slug}.csv";
        $fh   = fopen($file, 'wb');
        fputcsv($fh, ['role', 'name', 'mobile', 'badge_id', 'category'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ',', '"', '');
        }
        fputcsv($fh, [], ',', '"', '');
        fputcsv($fh, ['login', 'username', 'password'], ',', '"', '');
        foreach ($logins as $l) {
            fputcsv($fh, $l, ',', '"', '');
        }
        fclose($fh);
        @chmod($file, 0640);

        CLI::write("Slam #{$id} \"{$name}\" ({$slug}) on {$day}: status " . service('slams')->find($id)['status'], 'green');
        CLI::write("{$nPresent} presenters, {$nJudges} judges (4 categories), {$nExperts} experts. Operator {$opUser}, volunteer {$voUser}.");
        CLI::write("Badge IDs and logins: {$file}");
    }

    private function password(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out      = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
