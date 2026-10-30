<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Db;

/**
 * Several operators and admins can run one slam. A control action carries the state version its screen was showing
 * (`base_version`); if the slam has changed since (someone else pressed a button, a timer fired), the action is refused
 * with 409 `stale` and the screen refreshes, instead of acting on an out-of-date picture. Requests without a version
 * (older clients, scripts) are not checked; the services still enforce every rule.
 */
class FreshStateFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtoupper($request->getMethod()) === 'GET' || ! preg_match('#/api/slams/(\d+)/#', $request->getUri()->getPath(), $m)) {
            return null;
        }
        $body = null;
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            try {
                $body = $request->getJSON(true);
            } catch (\Throwable) {
                $body = null;
            }
        }
        $base = is_array($body) ? ($body['base_version'] ?? null) : null;
        if (! is_string($base) || $base === '') {
            return null;
        }
        $slamId = (int) $m[1];
        if (hash_equals(service('slamPhase')->stateVersion($slamId), $base)) {
            return null;
        }
        $last = Db::row("SELECT actor_label, action FROM activity_log WHERE slam_id = ? AND actor_type IN ('admin','system') ORDER BY id DESC LIMIT 1", [$slamId]);

        return service('response')->setStatusCode(409)->setJSON([
            'error' => 'The slam changed while you were looking at it' . ($last ? " (last change by {$last['actor_label']})" : '') . '. Your screen has been refreshed: check it and press the button again if it still applies.',
            'reason' => 'stale',
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
