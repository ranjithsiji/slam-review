<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Actor;

/**
 * Reviewer (user/judge) session. Every request re-validates that the check-in is
 * still open and bound to this session; after a forced check-out, ID reissue or
 * Slam Close the session ends at once.
 */
class ReviewerFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $sess  = session('reviewer');
        $isApi = str_starts_with($request->getUri()->getPath(), '/api/');
        $valid = is_array($sess) ? service('checkins')->validateSession($sess) : null;
        if ($valid !== null) {
            return null;
        }
        if (is_array($sess)) {
            $slam   = service('slams')->find((int) $sess['slam_id']);
            $closed = in_array($slam['status'], ['closed', 'marks_calculated', 'ended'], true);
            service('activityLog')->log(
                new Actor($sess['user_type'], (int) $sess['attendee_id'], $sess['badge_id'], (int) $sess['slam_id']),
                'auth.session_rejected',
                ['outcome' => 'denied', 'reason' => $closed ? 'slam_closed' : 'checkin_not_open'],
            );
            session()->remove('reviewer');
            session()->setFlashdata($closed ? 'thanks' : 'error', $closed
                ? 'The slam has closed. Thank you for reviewing!'
                : 'Your session has ended. If this is unexpected, please visit the volunteer desk.');
            if ($isApi) {
                return service('response')->setStatusCode(401)->setJSON(['error' => 'session_ended', 'reason' => $closed ? 'slam_closed' : 'checked_out']);
            }

            return redirect()->to(site_url($closed ? 'review/thanks' : 'checkin'));
        }
        if ($isApi) {
            return service('response')->setStatusCode(401)->setJSON(['error' => 'Please check in first.', 'reason' => 'not_checked_in']);
        }

        return redirect()->to(site_url('checkin'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
