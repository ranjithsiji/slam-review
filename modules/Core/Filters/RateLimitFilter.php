<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Token-bucket rate limit per IP (CI4 Throttler). Argument = bucket name:
 *   public (schedule pages/JSON), checkin, review, search, poll, staff.
 * Login lockouts (per ID, per IP) are handled separately by ThrottleService.
 */
class RateLimitFilter implements FilterInterface
{
    /** bucket => [capacity, seconds] */
    private const BUCKETS = [
        'public'  => [120, MINUTE],
        'poll'    => [60, MINUTE],
        'checkin' => [30, MINUTE],
        'review'  => [30, MINUTE],
        'search'  => [60, MINUTE],
        'staff'   => [600, MINUTE],
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $bucket          = $arguments[0] ?? 'public';
        [$cap, $seconds] = self::BUCKETS[$bucket] ?? self::BUCKETS['public'];
        $key             = 'rl_' . $bucket . '_' . md5($request->getIPAddress());
        if (service('throttler')->check($key, $cap, $seconds) === false) {
            return service('response')->setStatusCode(429)
                ->setHeader('Retry-After', (string) max(1, (int) service('throttler')->getTokenTime()))
                ->setJSON(['error' => 'Too many requests. Please slow down.']);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
