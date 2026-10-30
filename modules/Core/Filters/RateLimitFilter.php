<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Token-bucket rate limits (CI4 Throttler). Argument = bucket name.
 *
 * A whole venue usually shares ONE public IP (Wi-Fi NAT), so reviewer buckets
 * (poll, review) are keyed per check-in session, not per IP; per-IP buckets
 * (public, checkin) are sized for a full hall. Failed check-in/login attempts are
 * throttled separately, per ID and per IP, by ThrottleService.
 */
class RateLimitFilter implements FilterInterface
{
    /** bucket => [capacity, seconds] */
    private const BUCKETS = [
        'public'  => [1200, MINUTE], // per IP
        'checkin' => [600, MINUTE],  // per IP (gate rush through one NAT address)
        'poll'    => [60, MINUTE],   // per reviewer session (normal: ~15/min)
        'review'  => [30, MINUTE],   // per reviewer session
        'search'  => [120, MINUTE],  // per staff session
        'lookup'  => [10, MINUTE],   // per IP: public "Am I registered?" by mobile
        'staff'   => [600, MINUTE],  // per IP
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
        $bucket          = $arguments[0] ?? 'public';
        [$cap, $seconds] = self::BUCKETS[$bucket] ?? self::BUCKETS['public'];
        $who             = match ($bucket) {
            'poll', 'review' => 'r' . (session('reviewer')['checkin_id'] ?? $request->getIPAddress()),
            'search'         => 's' . (session('auth')['id'] ?? $request->getIPAddress()),
            default          => $request->getIPAddress(),
        };
        $key             = 'rl_' . $bucket . '_' . md5((string) $who);
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
