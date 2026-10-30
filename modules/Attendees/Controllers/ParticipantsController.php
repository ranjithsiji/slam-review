<?php

declare(strict_types=1);

namespace Modules\Attendees\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Modules\Core\Controllers\BaseController;
use Modules\Core\Libraries\Db;

/**
 * Participant screens in the sidebar: Reviewers (audience users), Judges and the
 * Audience representatives. The slam-less sidebar links resolve to a slam and
 * redirect; the screens themselves are per slam, with a slam switcher.
 */
class ParticipantsController extends BaseController
{
    /** /admin/{reviewers|judges|representatives}[?slam=ID] */
    public function go(string $kind): RedirectResponse
    {
        $asked = $this->request->getGet('slam');
        $id    = service('slams')->currentId(is_numeric($asked) ? (int) $asked : null);
        if ($id === null) {
            return redirect()->to(site_url('admin/slams'))->with('info', 'Create a slam first; reviewers, judges and representatives belong to a slam.');
        }

        return redirect()->to(site_url("admin/slams/{$id}/{$kind}"));
    }

    public function reviewers(int $slamId): string
    {
        $slam   = service('slams')->find($slamId);
        $scores = service('scoring');
        $reps   = service('representatives');

        return $this->render('Modules\Attendees\Views\reviewers', [
            'title' => 'Reviewers — ' . $slam['name'], 'slam' => $slam,
            'counts' => [
                'users'          => (int) Db::value("SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND user_type = 'user' AND is_active = 1", [$slamId]),
                'checked_in'     => (int) Db::value("SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND a.user_type = 'user' AND c.checked_out_at IS NULL", [$slamId]),
                'complete'       => count($scores->completeReviewerIds($slamId)),
                'representatives' => count($reps->ids($slamId)),
                'opened'         => count($scores->openedItemIds($slamId)),
            ],
            'repsLocked' => $reps->lockedReason($slam), 'repsWanted' => $reps->count($slamId),
        ]);
    }

    public function judges(int $slamId): string
    {
        $slam   = service('slams')->find($slamId);
        $scores = service('scoring');

        return $this->render('Modules\Attendees\Views\judges', [
            'title' => 'Judges — ' . $slam['name'], 'slam' => $slam,
            'counts' => [
                'judges'     => (int) Db::value("SELECT COUNT(*) FROM attendees WHERE slam_id = ? AND user_type = 'judge' AND is_active = 1", [$slamId]),
                'checked_in' => (int) Db::value("SELECT COUNT(*) FROM checkins c JOIN attendees a ON a.id = c.attendee_id WHERE a.slam_id = ? AND a.user_type = 'judge' AND c.checked_out_at IS NULL", [$slamId]),
                'opened'     => count($scores->openedItemIds($slamId)),
            ],
            'judging' => $scores->judging($slamId), 'progress' => service('judgeMarks')->progress($slamId),
            'marksLocked' => service('judgeMarks')->lockedReason($slam),
        ]);
    }
}
