<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Db;
use Modules\Core\Libraries\OperatorAccess;
use Modules\Core\Libraries\OperatorMode;

/**
 * Staff session auth. Arguments: 'admin', 'volunteer' or both ('admin,volunteer').
 * Re-checks on every request that the account is still active.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $allowed = $arguments ?: ['admin'];
        $auth    = session('auth');
        $isApi   = str_starts_with($request->getUri()->getPath(), '/api/') || str_contains($request->getHeaderLine('Accept'), 'application/json');

        $ok = is_array($auth) && in_array($auth['type'], $allowed, true);
        if ($ok) {
            $table = $auth['type'] === 'admin' ? 'admins' : 'volunteers';
            $row   = Db::row('SELECT is_active, password_changed_at' . ($table === 'admins' ? ', role, slam_id' : '') . " FROM {$table} WHERE id = ?", [$auth['id']]);
            // Inactive, or the password was changed/reset since this session signed in.
            $ok    = $row !== null && (int) $row['is_active'] === 1 && (string) ($row['password_changed_at'] ?? '') === (string) ($auth['pwd'] ?? '');
            if (! $ok) {
                session()->remove('auth');
                $auth = null; // signed out: send them to the login page, not a bare 403
            }
        }
        if ($ok && $auth['type'] === 'admin') {
            // The role and slam come from the database, so changing them takes effect on the next request.
            $role = (string) $row['role'];
            $slam = $role === 'operator' ? (int) $row['slam_id'] : null;
            if (($auth['role'] ?? 'admin') !== $role || ($auth['slam_id'] ?? null) !== $slam) {
                $auth = array_merge($auth, ['role' => $role, 'slam_id' => $slam]);
                session()->set('auth', $auth);
            }
            if ($role === 'operator' && ! $isApi && strtoupper($request->getMethod()) === 'GET') {
                OperatorMode::syncFromPath((int) $slam, $request->getUri()->getPath());
            }
            if ($role === 'operator' && ! OperatorAccess::allows($request, (int) $slam)) {
                return $isApi
                    ? service('response')->setStatusCode(403)->setJSON(['error' => 'Not allowed for a slam operator.'])
                    : redirect()->to(site_url('admin'))->with('error', 'That screen is not available to a slam operator.');
            }
        }
        if ($ok) {
            return null;
        }
        if ($isApi) {
            return service('response')->setStatusCode(is_array($auth) ? 403 : 401)->setJSON(['error' => is_array($auth) ? 'Not allowed.' : 'Please sign in.']);
        }
        if (is_array($auth)) {
            return service('response')->setStatusCode(403)->setBody('Not allowed.');
        }
        $loginPath = in_array('admin', $allowed, true) ? 'admin/login' : 'volunteer/login';

        return redirect()->to(site_url($loginPath))->with('error', 'Please sign in.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
