<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Libraries\Actor;
use Modules\Core\Libraries\DomainException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base for every module controller. Controllers stay thin: parse input, call a
 * service, render a view or JSON. DomainException → friendly message / JSON error.
 */
abstract class BaseController extends Controller
{
    protected $helpers = ['form', 'url', 'ui'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
    }

    protected function actor(): Actor
    {
        return Actor::current();
    }

    /** JSON body or form fields. */
    protected function input(): array
    {
        $ct = $this->request->getHeaderLine('Content-Type');
        if (str_contains($ct, 'application/json')) {
            try {
                $data = $this->request->getJSON(true);
            } catch (Throwable) {
                $data = null;
            }

            return is_array($data) ? $data : [];
        }

        return (array) $this->request->getPost();
    }

    protected function json(mixed $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }

    protected function jsonError(DomainException $e): ResponseInterface
    {
        return $this->json(['error' => $e->getMessage(), 'reason' => $e->reason, 'details' => $e->details ?: null], $e->status);
    }

    /**
     * Run a service call for an API endpoint: domain errors → JSON error,
     * anything unexpected → 500 with a reference id (logged as system.error).
     *
     * @param callable(): mixed $fn
     */
    protected function api(callable $fn): ResponseInterface
    {
        try {
            $result = $fn();

            return $result instanceof ResponseInterface ? $result : $this->json($result ?? ['ok' => true]);
        } catch (DomainException $e) {
            return $this->jsonError($e);
        } catch (Throwable $e) {
            return $this->json(['error' => 'Something went wrong. Reference: ' . $this->reportError($e)], 500);
        }
    }

    /**
     * Run a form action: on success redirect with a flash message, on a domain
     * error go back with the message and field errors.
     *
     * @param callable(): (string|RedirectResponse) $fn returns the redirect URL or a response
     */
    protected function formAction(callable $fn, string $success): RedirectResponse
    {
        try {
            $to = $fn();

            return $to instanceof RedirectResponse ? $to->with('success', $success) : redirect()->to($to)->with('success', $success);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage())->with('errors', $e->details);
        } catch (Throwable $e) {
            return redirect()->back()->withInput()->with('error', 'Something went wrong. Reference: ' . $this->reportError($e));
        }
    }

    protected function reportError(Throwable $e): string
    {
        $ref = bin2hex(random_bytes(4));
        log_message('error', "[{$ref}] " . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        try {
            service('activityLog')->log($this->actor(), 'system.error', ['outcome' => 'failure', 'meta' => ['ref' => $ref, 'type' => $e::class]]);
        } catch (Throwable) {
        }
        if (ENVIRONMENT !== 'production') {
            log_message('error', $e->getTraceAsString());
        }

        return $ref;
    }

    /** Render a module view inside a layout. */
    protected function render(string $view, array $data = [], string $layout = 'admin'): string
    {
        $data['errors'] ??= session('errors') ?? [];

        return view($view, $data + ['layout' => $layout]);
    }

    /** Stream a download that must not be cached or stored. */
    protected function download(string $body, string $filename, string $mime): ResponseInterface
    {
        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($body);
    }
}
