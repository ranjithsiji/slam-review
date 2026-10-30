<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Db;

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
            $ok    = (int) Db::value("SELECT is_active FROM {$table} WHERE id = ?", [$auth['id']]) === 1;
            if (! $ok) {
                session()->remove('auth');
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
