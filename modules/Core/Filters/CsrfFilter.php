<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use Config\Session;

/**
 * CSRF protection as before, but a failed public form (check-in, "Am I registered?", "Get my badge") goes back to
 * its page with an explanation instead of the error page. The flash message cannot be used: when the browser
 * keeps no cookie (a QR app's own browser, cookies blocked) there is no session, so the reason goes in the URL.
 */
class CsrfFilter extends CSRF
{
    private const PUBLIC_FORMS = ['checkin' => 'checkin', 'checkin/confirm' => 'checkin', 'registered' => 'registered', 'badge' => 'badge'];

    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            return parent::before($request, $arguments);
        } catch (SecurityException $e) {
            $path = trim($request->getUri()->getPath(), '/');
            $page = self::PUBLIC_FORMS[$path] ?? null;
            if ($page === null || str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                throw $e;
            }
            $hasCookie = $request->getCookie(config(Session::class)->cookieName) !== null;
            log_message('notice', 'Public form token mismatch on ' . $path . ($hasCookie ? ' (stale page)' : ' (no cookie)'));

            return redirect()->to(site_url($page) . '?' . ($hasCookie ? 'retry=1' : 'nocookie=1'));
        }
    }
}
