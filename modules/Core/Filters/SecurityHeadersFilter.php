<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Global security headers. Every asset is self-hosted (fonts, icons, theme), so
 * the CSP can be strict: no external hosts, no inline scripts. Inline styles are
 * allowed because Tabler/DataTables/Vue set style attributes.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
            . "font-src 'self'; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'self'; "
            . "form-action 'self'; frame-ancestors 'none'";
        if (! $response->hasHeader('Content-Security-Policy')) {
            $response->setHeader('Content-Security-Policy', $csp);
        }
        $response->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        if (! $response->hasHeader('Referrer-Policy')) {
            $response->setHeader('Referrer-Policy', 'same-origin');
        }

        return $response;
    }
}
