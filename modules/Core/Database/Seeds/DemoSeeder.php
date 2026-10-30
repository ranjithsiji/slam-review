<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Modules\Accounts\Services\AuthService;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\Clock;
use Modules\Core\Libraries\Db;

/**
 * Demo data: 1 admin, 1 volunteer, placeholder question templates, 1 slam with
 * 5 performances in the schedule, and 50 attendees (45 users, 5 judges).
 *
 *   php spark db:seed 'Modules\Core\Database\Seeds\DemoSeeder'
 *
 * Question wording and all durations are PLACEHOLDERS for the organisers to replace.
 */
class DemoSeeder extends Seeder
{
    public const ADMIN_USER     = 'admin';
    public const ADMIN_PASS     = 'ChangeMe-2026!';
    public const VOLUNTEER_USER = 'volunteer1';
    public const VOLUNTEER_PASS = 'Volunteer-2026!';

    public function run(): void
    {
        $sys = Actor::system();
        if (Db::value('SELECT id FROM admins WHERE username = ?', [self::ADMIN_USER]) === null) {
            service('auth')->createAdmin(self::ADMIN_USER, 'Slam Admin', self::ADMIN_PASS, $sys);
        }
        $admin = new Actor('admin', (int) Db::value('SELECT id FROM admins WHERE username = ?', [self::ADMIN_USER]), self::ADMIN_USER);

        $this->seedTemplates();

        // Slam tomorrow (IST): open 08:30, gates 09:00–10:00, reviews 10:15, close 17:00.
        $day = Clock::now()->setTimezone(new \DateTimeZone(Clock::tz()))->modify('+1 day')->format('Y-m-d');
        $slamId = (int) (Db::value('SELECT id FROM slams WHERE slug = ?', ['demo-regional-slam']) ?? 0);
        if ($slamId === 0) {
            $slamId = service('slams')->create([
                'name' => 'Kerala Science Slam — Demo Regional', 'type' => 'regional', 'slug' => 'demo-regional-slam',
                'description' => 'Demo slam created by the seeder.',
                'venue_name' => 'Demo Auditorium', 'address_line' => 'University Campus', 'city' => 'Thiruvananthapuram',
                'district' => 'Thiruvananthapuram', 'state' => 'Kerala', 'pincode' => '695034',
                'map_url' => 'https://www.openstreetmap.org/', 'contact_name' => 'Organising Desk', 'contact_phone' => '9447000000',
                'opens_at' => Clock::fromLocal($day, '08:30'), 'checkin_opens_at' => Clock::fromLocal($day, '09:00'),
                'checkin_closes_at' => Clock::fromLocal($day, '10:00'), 'reviews_start_at' => Clock::fromLocal($day, '10:15'),
                'closes_at' => Clock::fromLocal($day, '17:00'),
                'default_review_minutes' => 5, 'changeover_minutes' => 2, 'edit_window_minutes' => 10, 'close_grace_minutes' => 5,
                'default_tea_break_minutes' => 15, 'default_lunch_break_minutes' => 45,
            ], $admin);
        }

        if ((int) Db::value('SELECT COUNT(*) FROM performances WHERE slam_id = ?', [$slamId]) === 0) {
            $talks = [
                ['Anjali Menon', 'IISER Thiruvananthapuram', 'Why monsoon clouds forget to rain', 'Cloud microphysics for everyone.'],
                ['Rahul Krishnan', 'CUSAT', 'The secret life of mangrove roots', 'How mangroves breathe and protect our coast.'],
                ['ഫാത്തിമ നസ്രിൻ', 'University of Kerala', 'കായലിലെ സൂക്ഷ്മജീവികൾ', 'Microbes of the backwaters, explained in Malayalam.'],
                ['Joseph Varghese', 'NIT Calicut', 'Teaching robots to pluck coconuts', 'Computer vision in the canopy.'],
                ['Lakshmi Pillai', 'KFRI Peechi', 'Seeds that travel by elephant', 'Seed dispersal in the Western Ghats.'],
            ];
            foreach ($talks as [$name, $inst, $topic, $abstract]) {
                service('presenters')->addToSlam($slamId, [
                    'name' => $name, 'designation_or_institution' => $inst, 'profile' => "{$name} is a researcher at {$inst}.",
                    'topic' => $topic, 'abstract' => $abstract,
                ], $admin);
            }
        }

        if ((int) Db::value('SELECT COUNT(*) FROM attendees WHERE slam_id = ?', [$slamId]) === 0) {
            $first = ['Arjun', 'Meera', 'Nikhil', 'Sneha', 'Vishnu', 'Aparna', 'Gokul', 'Divya', 'Hari', 'Keerthana'];
            $last  = ['Nair', 'Menon', 'Pillai', 'Thomas', 'Kurian', 'Varma', 'Joseph', 'Das'];
            for ($i = 0; $i < 50; $i++) {
                service('attendees')->create($slamId, [
                    'name' => $first[$i % 10] . ' ' . $last[intdiv($i, 10) % 8],
                    'mobile' => (string) (9000000000 + $i * 7919 + 101),
                    'email' => $i % 3 === 0 ? 'attendee' . $i . '@example.com' : '',
                    'user_type' => $i < 5 ? 'judge' : 'user',
                ], $admin);
            }
        }

        if (Db::value('SELECT id FROM volunteers WHERE username = ?', [self::VOLUNTEER_USER]) === null) {
            service('auth')->saveVolunteer(null, [
                'username' => self::VOLUNTEER_USER, 'name' => 'Desk Volunteer', 'slam_id' => $slamId,
                'password' => self::VOLUNTEER_PASS, 'is_active' => 1,
            ], $admin);
        }

        // Ready for its timeline (Setup → ready).
        $slam = service('slams')->find($slamId);
        if ($slam['status'] === 'draft') {
            service('slamPhase')->markReady($slamId, $admin);
        }
    }

    private function seedTemplates(): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM review_questions WHERE slam_id IS NULL') > 0) {
            return;
        }
        $db  = db_connect();
        $now = Clock::db();
        // Audience: 2 questions × 5 marks (wording still to come). Judges: the organisers' evaluation criteria.
        $rows = [
            ['user', 'Question 1 (placeholder — replace before the first slam)', 10, 5, null],
            ['user', 'Question 2 (placeholder — replace before the first slam)', 20, 5, null],
            ['judge', 'Communication', 10, 3, 'Effective for the general public: little or no jargon or technical terms, the overall idea is not lost, no false, pseudo or pseudoscientific information.'],
            ['judge', 'Engagement', 20, 3, "An engaging story; a pause for a question and the audience's response to it; models, experiments, tricks, song, dance or anything else that draws the public in."],
            ['judge', 'Depth of knowledge', 30, 3, 'Subject knowledge, novelty of the research idea, importance of the results and the way they are communicated. Give a lower mark for any wrong concept.'],
            ['judge', 'Audience response', 40, 1, "Based on the audience's reactions (emoji or memes) during the talk."],
        ];
        foreach ($rows as [$aud, $text, $pos, $max, $help]) {
            $db->table('review_questions')->insert([
                'slam_id' => null, 'audience' => $aud, 'text' => $text, 'help_text' => $help, 'weight' => 1, 'position' => $pos, 'is_active' => 1,
                'max_score' => $max,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
