<?php

declare(strict_types=1);

namespace Modules\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** For pages whose URL or content is a credential (check-in /in/{ID}, exports). */
class NoStoreFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response->setHeader('Cache-Control', 'no-store, private')->setHeader('Referrer-Policy', 'no-referrer');
    }
}
