<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

/**
 * A slam operator works in one of three modes, which only decides what the menu and the
 * home page offer (what they may do is decided by OperatorAccess and the slam phase):
 *  - prepare: Slam setup mode, before the slam (presenters, judges, participants, schedule, times, weights)
 *  - run:     Slam running mode, on the day (Status controls, schedule changes, check-ins)
 *  - after:   Slam after mode, the marks (judges' marks, representatives, calculate, scores, leaderboard)
 * Until the operator chooses, the mode follows the slam: setup phases = prepare, closed or later = after,
 * anything in between = run.
 */
final class OperatorMode
{
    public const PREPARE = 'prepare';
    public const RUN     = 'run';
    public const AFTER   = 'after';

    public const LABELS = [self::PREPARE => 'Slam setup mode', self::RUN => 'Slam running mode', self::AFTER => 'Slam after mode'];

    /** Screens (slam_nav keys) per mode, in menu order. A screen in several modes does not switch the mode. */
    public const TABS = [
        self::PREPARE => ['overview', 'edit', 'presenters', 'schedule', 'judgelist', 'reviewers', 'questions', 'ads', 'weights'],
        self::RUN     => ['status', 'schedule', 'checkins', 'idle', 'reviewers'],
        self::AFTER   => ['status', 'judges', 'paper', 'representatives', 'marks', 'scores', 'leaderboard', 'stats'],
    ];

    /** Menu entry per screen: [label, path under admin/slams/{id}, icon]. */
    public const LINKS = [
        'overview' => ['Overview', '', 'nav.slams'], 'edit' => ['Times & details', '/edit', 'nav.schedule'],
        'presenters' => ['Presenters', '/presenters', 'nav.presenters'], 'schedule' => ['Schedule', '/schedule', 'nav.schedule'],
        'judgelist' => ['Experts', '/judges', 'person.judge'], 'reviewers' => ['Judges & import', '/reviewers', 'person.user'],
        'questions' => ['Questions', '/questions', 'nav.questions'], 'ads' => ['Break ads', '/ads', 'about.talk'], 'weights' => ['Mark weights', '/mark-config', 'nav.system'],
        'status' => ['Status & controls', '/status', 'nav.status'], 'checkins' => ['Check-ins', '/checkins', 'nav.checkins'], 'idle' => ['Not reviewing', '/not-reviewing', 'status.warning'],
        'judges' => ["Experts' marks", '/judge-marks', 'person.judge'], 'paper' => ['Paper marks', '/paper-marks', 'action.edit'], 'representatives' => ['Audience representatives', '/representatives', 'nav.representatives'],
        'marks' => ['Calculate marks', '/marks', 'nav.marks'], 'scores' => ['Final scores', '/scores', 'nav.marks'], 'leaderboard' => ['Leaderboard', '/leaderboard', 'nav.marks'], 'stats' => ['Statistics', '/stats', 'nav.stats'],
    ];

    public static function current(int $slamId): string
    {
        $chosen = session('op_mode');
        if (isset(self::LABELS[$chosen])) {
            return $chosen;
        }
        $status = Db::value('SELECT status FROM slams WHERE id = ?', [$slamId]);

        return match (true) {
            in_array($status, ['draft', 'scheduled'], true) => self::PREPARE,
            in_array($status, ['closed', 'marks_calculated', 'ended'], true) => self::AFTER,
            default => self::RUN,
        };
    }

    /**
     * Opening a screen that belongs to only one mode switches to that mode (so the menu always matches
     * the screen); screens in both modes, like the schedule, leave the mode alone.
     */
    public static function syncFromPath(int $slamId, string $path): void
    {
        foreach (self::LINKS as $key => [, $suffix]) {
            if (! preg_match('#^/?admin/slams/' . $slamId . preg_quote($suffix, '#') . ($suffix === '' ? '/?$' : '(/|$)') . '#', $path)) {
                continue;
            }
            $modes = array_keys(array_filter(self::TABS, static fn (array $tabs) => in_array($key, $tabs, true)));
            if (count($modes) === 1) {
                self::set($modes[0]);
            }

            return;
        }
    }

    public static function set(string $mode): void
    {
        session()->set('op_mode', isset(self::LABELS[$mode]) ? $mode : self::PREPARE);
    }

    public static function homeUrl(int $slamId, string $mode): string
    {
        return site_url(match ($mode) {
            self::RUN   => "admin/slams/{$slamId}/status",
            self::AFTER => "admin/slams/{$slamId}/marks",
            default     => "admin/slams/{$slamId}",
        });
    }
}
