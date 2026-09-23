<?php

namespace FlareWeber\Compiler;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

/**
 * Fallback renderer used only when the loopback HTTP request cannot reach
 * the frontend (for example when the bundled server accepts a single
 * connection). Pages are dispatched through the HTTP kernel in-process as
 * an anonymous visitor: the current user is logged out first and the
 * request/auth state is restored afterwards.
 *
 * Caveat: Microweber defines page constants (PAGE_ID, CONTENT_ID ...) once
 * per PHP process, so multi-page in-process renders may see stale values.
 */
class InProcessRenderer implements RendererInterface
{
    private const MAX_REDIRECTS = 5;

    public function __construct(private readonly UrlContext $ctx)
    {
    }

    public function render(string $requestPath): RenderedPage
    {
        $app = app();
        $originalRequest = $app->bound('request') ? $app->make('request') : null;
        $originalServer = $_SERVER;
        $originalUser = null;

        try {
            $originalUser = Auth::user();
        } catch (\Throwable) {
            // No guard configured; nothing to restore.
        }

        try {
            $this->logout();
            $this->pinSiteUrl();

            $path = str_starts_with($requestPath, '/') ? $requestPath : '/' . $requestPath;

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $request = Request::create($this->ctx->origin() . $path, 'GET');
                $request->headers->set(LoopbackRenderer::HEADER, '1');
                $request->headers->set('Accept', 'text/html');
                $request->headers->remove('Cookie');
                $request->cookies->replace([]);

                $this->fakeServerGlobals($path);
                $app->instance('request', $request);
                Facade::clearResolvedInstance('request');

                $response = $app->make(Kernel::class)->handle($request);
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = (string) $response->headers->get('Location');
                    $next = $this->sameOrigin($location);
                    if ($next === null) {
                        return new RenderedPage($status, null, '', null);
                    }
                    $path = $next;

                    continue;
                }

                return new RenderedPage(
                    $status,
                    (string) $response->getContent(),
                    (string) ($response->headers->get('Content-Type') ?: 'text/html'),
                    $path
                );
            }

            return RenderedPage::failed('too many redirects');
        } catch (\Throwable $e) {
            return RenderedPage::failed('in-process: ' . $e->getMessage());
        } finally {
            $_SERVER = $originalServer;
            if ($originalRequest !== null) {
                $app->instance('request', $originalRequest);
            }
            Facade::clearResolvedInstance('request');

            if ($originalUser !== null) {
                try {
                    Auth::setUser($originalUser);
                } catch (\Throwable) {
                    // Best effort restore.
                }
            }
        }
    }

    public function fetch(string $requestPath, int $maxBytes = 26214400): ?array
    {
        $rendered = $this->render($requestPath);
        if ($rendered->status !== 200 || $rendered->html === null || $rendered->isHtml() || strlen($rendered->html) > $maxBytes) {
            return null;
        }

        return ['content_type' => strtolower(trim(explode(';', $rendered->contentType)[0])), 'body' => $rendered->html];
    }

    /**
     * Make sure nothing renders as the logged-in admin: live-edit chrome and
     * drafts are gated on is_admin() which reads the auth guard.
     */
    private function logout(): void
    {
        try {
            Auth::logout();
        } catch (\Throwable) {
            // Guard without session support; continue with the explicit reset below.
        }

        try {
            $guard = Auth::guard();
            if (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        } catch (\Throwable) {
            // Ignore: the guard may not be resolvable outside HTTP.
        }

        $app = app();
        foreach (['user_manager', 'mw.user_manager'] as $abstract) {
            if ($app->bound($abstract)) {
                $app->forgetInstance($abstract);
            }
        }
    }

    /**
     * Microweber's site_url() caches whatever it computed from the first
     * request's globals (on the CLI: the artisan script's directory), but it
     * re-reads the MW_SITE_URL constant on every call. Pin it to the compile
     * origin so page paths resolve; this is process-wide and intentional.
     */
    private function pinSiteUrl(): void
    {
        if (!defined('MW_SITE_URL')) {
            define('MW_SITE_URL', $this->ctx->origin() . '/');
        }

        try {
            app()->url_manager->set($this->ctx->origin() . '/');
        } catch (\Throwable) {
            // URL manager unavailable outside Microweber; nothing to pin.
        }
    }

    /**
     * Microweber's URL manager reads the raw superglobals (not the Laravel
     * request); on the CLI they describe the artisan script, which makes
     * every frontend path a 404. Describe a real web request instead.
     */
    private function fakeServerGlobals(string $path): void
    {
        $query = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
        $isHttps = $this->ctx->scheme === 'https';

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = base_path('index.php');
        $_SERVER['DOCUMENT_ROOT'] = base_path();
        $_SERVER['HTTP_HOST'] = $this->ctx->host . ($this->ctx->port === ($isHttps ? 443 : 80) ? '' : ':' . $this->ctx->port);
        $_SERVER['SERVER_NAME'] = $this->ctx->host;
        $_SERVER['SERVER_PORT'] = (string) $this->ctx->port;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['HTTPS'] = $isHttps ? 'on' : 'off';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', LoopbackRenderer::HEADER))] = '1';
        unset($_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER'], $_SERVER['argv'], $_SERVER['argc']);
    }

    private function sameOrigin(string $location): ?string
    {
        $location = trim($location);
        if ($location === '' || str_starts_with($location, '//')) {
            return null;
        }
        if (str_starts_with($location, '/')) {
            return $location;
        }
        if (!$this->ctx->isLocal($location)) {
            return null;
        }
        $parts = parse_url($location) ?: [];

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
