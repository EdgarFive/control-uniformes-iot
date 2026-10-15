<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Filters\SessionAuth;

class PanelFilter extends SessionAuth
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = parent::before($request, $arguments);
        if ($response !== null) {
            return $response;
        }

        if (! auth('session')->user()?->inGroup('admin', 'superadmin')) {
            return service('response')->setStatusCode(403)->setBody(view('admin/denegado'));
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        // Private pages and photographs must never enter a shared browser/proxy cache.
        $response->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Referrer-Policy', 'same-origin');
    }
}
