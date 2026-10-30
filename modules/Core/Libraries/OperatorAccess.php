<?php

declare(strict_types=1);

namespace Modules\Core\Libraries;

use CodeIgniter\HTTP\RequestInterface;

/**
 * What a slam operator may reach. An operator is an admin account bound to one
 * slam, so this is a DEFAULT-DENY allowlist: a route that is not listed here is
 * closed to operators, and every listed route must resolve to the operator's own
 * slam (from the URL, from the object the URL names, or from the request body).
 */
final class OperatorAccess
{
    /** Tables an operator may read, and the filter that names their slam. */
    private const TABLES = [
        'performances' => 'slam_id', 'attendees' => 'slam_id', 'import-batches' => 'slam_id', 'import-rows' => 'batch_id',
        'checkins' => 'slam_id', 'idle-reviewers' => 'slam_id', 'attendee-search' => 'slam_id', 'questions' => 'slam_id', 'marks' => 'slam_id',
        'mark-stats' => 'slam_id', 'complete-reviewers' => 'slam_id', 'mark-runs' => 'slam_id',
        'schedule-snapshots' => 'slam_id', 'phase-log' => 'slam_id', 'activity-log' => 'slam_id',
    ];

    /**
     * [methods, path regex, scope]. Scope is 'any' (nothing slam-specific), 'slam' (first capture is the slam id),
     * 'body' (slam_id in the request), 'attendee-body' (attendee_id in the request) or [sql, 'many'?] where the
     * first capture is an object id and the SQL returns its slam id(s).
     */
    private static function rules(): array
    {
        $read = ['GET'];
        $all  = ['GET', 'POST', 'PUT', 'DELETE'];

        return [
            [$read, '#^admin$#', 'any'],
            [['GET', 'POST'], '#^admin/account(/password)?$#', 'any'],
            [['POST'], '#^operator/mode$#', 'any'],
            [$read, '#^api/admin/live$#', 'any'],
            [['POST'], '#^api/tables/([a-z-]+)$#', 'tables'],
            [$all, '#^admin/slams/(\d+)(/.*)?$#', 'slam'],
            [$all, '#^api/slams/(\d+)(/.*)?$#', 'slam'],

            [['GET', 'POST'], '#^admin/attendees/(\d+)/(edit|reissue-id)$#', ['SELECT slam_id FROM attendees WHERE id = ?']],
            [['POST'], '#^admin/attendees/(\d+)$#', ['SELECT slam_id FROM attendees WHERE id = ?']],
            [$read, '#^admin/attendees/import/template$#', 'any'],
            [['POST'], '#^admin/attendees/import/preview$#', 'body'],
            [['GET', 'POST'], '#^admin/attendees/import/(?:commit/|cancel/)?(\d+)(?:/errors\.csv)?$#', ['SELECT slam_id FROM import_batches WHERE id = ?']],
            [['POST'], '#^api/import-batches/(\d+)/deactivate$#', ['SELECT slam_id FROM import_batches WHERE id = ?']],

            [['GET', 'POST'], '#^admin/presenters/(\d+)(/edit|/remove-photo)?$#', ['SELECT slam_id FROM performances WHERE presenter_id = ?', 'many']],
            [['POST'], '#^api/presenters/(\d+)/photo$#', ['SELECT slam_id FROM performances WHERE presenter_id = ?', 'many']],
            [['GET', 'POST'], '#^admin/performances/(\d+)(/edit)?$#', ['SELECT slam_id FROM performances WHERE id = ?']],

            [['PUT', 'DELETE', 'POST'], '#^api/schedule/items/(\d+)(/extend-break|/end-break)?$#', ['SELECT slam_id FROM schedule_items WHERE id = ?']],

            [['POST'], '#^api/questions$#', 'body'],
            [['POST'], '#^api/questions/reorder$#', 'body'],
            [['POST'], '#^api/questions/copy-from/\d+$#', 'body'],
            [['PUT', 'DELETE'], '#^api/questions/(\d+)$#', ['SELECT slam_id FROM review_questions WHERE id = ?']],

            [$read, '#^admin/mark-runs/(\d+)$#', ['SELECT slam_id FROM mark_runs WHERE id = ?']],

            // The check-in desk (late check-in, force check-out, search) for their own slam.
            [$read, '#^volunteer(/not-reviewing)?$#', 'any'],
            [$all, '#^api/volunteer/(state|checkin|idle-checkout)$#', 'any'],
            [['GET'], '#^api/attendees/search$#', 'any'],
            [['POST'], '#^api/force-checkout/(\d+)$#', ['SELECT slam_id FROM attendees WHERE id = ?']],
            [['POST'], '#^api/checkin/late-override$#', 'attendee-body'],
        ];
    }

    /** True when the operator may make this request. */
    public static function allows(RequestInterface $request, int $slamId): bool
    {
        $path   = trim($request->getUri()->getPath(), '/');
        $method = strtoupper($request->getMethod());
        foreach (self::rules() as [$methods, $re, $scope]) {
            if (! in_array($method, $methods, true) || ! preg_match($re, $path, $m)) {
                continue;
            }

            return self::inScope($request, $slamId, $scope, $m);
        }

        return false;
    }

    /** @param array<int, string> $m */
    private static function inScope(RequestInterface $request, int $slamId, string|array $scope, array $m): bool
    {
        if ($scope === 'any') {
            return true;
        }
        if ($scope === 'slam') {
            return (int) $m[1] === $slamId;
        }
        if ($scope === 'body') {
            $v = self::param($request, 'slam_id');

            return $v !== null && (int) $v === $slamId;
        }
        if ($scope === 'attendee-body') {
            $a = self::param($request, 'attendee_id');

            return $a !== null && self::slamsOf('SELECT slam_id FROM attendees WHERE id = ?', (int) $a) === [$slamId];
        }
        if ($scope === 'tables') {
            $key = self::TABLES[$m[1]] ?? null;
            if ($key === null) {
                return false;
            }
            $filters = (array) (self::param($request, 'filters') ?? []);
            $v       = $filters[$key] ?? null;
            if ($key === 'batch_id') {
                return $v !== null && self::slamsOf('SELECT slam_id FROM import_batches WHERE id = ?', (int) $v) === [$slamId];
            }

            return $v !== null && ctype_digit((string) $v) && (int) $v === $slamId;
        }
        $slams = self::slamsOf($scope[0], (int) $m[1]);

        // Every slam the object belongs to must be theirs, except a shared presenter, who only needs to be in their slam.
        return isset($scope[1]) ? in_array($slamId, $slams, true) : ($slams !== [] && $slams === [$slamId]);
    }

    /** @return list<int> */
    private static function slamsOf(string $sql, int $id): array
    {
        return array_values(array_unique(array_map('intval', array_column(Db::rows($sql, [$id]), 'slam_id'))));
    }

    private static function param(RequestInterface $request, string $key): mixed
    {
        $json = null;
        if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            try {
                $json = $request->getJSON(true);
            } catch (\Throwable) {
                $json = null;
            }
        }

        return (is_array($json) ? ($json[$key] ?? null) : null) ?? $request->getPost($key) ?? $request->getGet($key);
    }
}
