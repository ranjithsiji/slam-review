<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Volunteers may only touch their own slam. For routes that carry a slam id as
 * the first numeric segment after the prefix (e.g. /volunteer/...), the id must
 * match; services re-check the scope of every attendee they act on.
 */
class SlamScopeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $auth = session('auth');
        if (! is_array($auth) || $auth['type'] !== 'volunteer') {
            return null;
        }
        if (preg_match('#/slams/(\d+)#', $request->getUri()->getPath(), $m) && (int) $m[1] !== (int) $auth['slam_id']) {
            return service('response')->setStatusCode(403)->setJSON(['error' => 'Not allowed for this slam.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
