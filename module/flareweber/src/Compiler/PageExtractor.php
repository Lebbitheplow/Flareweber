<?php

namespace FlareWeber\Compiler;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Captures published pages from the bundled Microweber instance by
 * dispatching internal requests through the HTTP kernel. No web server or
 * loopback URL needed: the app renders its own frontend in-process.
 * Requests are anonymous, so no admin/live-edit chrome is included beyond
 * what the theme ships; HtmlCompiler strips the rest.
 */
class PageExtractor
{
    private const SKIP_PREFIXES = [
        '/admin', '/api', '/login', '/logout', '/register', '/cart',
        '/checkout', '/account', '/search', '/preview', '/live_edit',
        '/userfiles', '/microweber',
    ];

    /**
     * @return array<string, string> page path => cleaned HTML
     */
    public function extract(string $baseUrl, HtmlCompiler $compiler, int $maxPages = 50): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $queue = ['/'];
        $seen = ['/'];
        $pages = [];

        while ($queue !== [] && count($pages) < $maxPages) {
            $path = array_shift($queue);
            $html = $this->render($path);

            if ($html === null) {
                continue;
            }

            $pages[$path] = $compiler->clean($html);

            foreach ($this->discoverLinks($pages[$path], $baseUrl, $seen) as $next) {
                $seen[] = $next;
                $queue[] = $next;
            }
        }

        $pagePaths = array_keys($pages);
        $rewritten = [];
        foreach ($pages as $path => $html) {
            $rewritten[$path] = $compiler->rewrite($html, $baseUrl, $pagePaths, []);
        }

        return $rewritten;
    }

    /**
     * @return array<int, string>
     */
    public function discoverLinks(string $html, string $baseUrl, array $seen): array
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        $found = [];

        preg_match_all('#<a\b[^>]*href="([^"]+)"#i', $html, $matches);

        foreach ($matches[1] ?? [] as $href) {
            if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $path = null;
            if (str_starts_with($href, '/')) {
                $path = $href;
            } elseif ($host !== '' && stripos($href, $host) !== false) {
                $path = parse_url($href, PHP_URL_PATH);
            }

            if (!is_string($path) || $path === '') {
                continue;
            }

            $path = strtok($path, '?#') ?: '/';
            $path = '/' . trim($path, '/');
            if ($path !== '/' && str_ends_with($path, '/')) {
                $path = rtrim($path, '/');
            }

            $lower = strtolower($path);
            if (in_array($lower, $seen, true)) {
                continue;
            }

            $skip = false;
            foreach (self::SKIP_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip && pathinfo($path, PATHINFO_EXTENSION) === '') {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Render a frontend route inside the bundled application.
     */
    public function render(string $path): ?string
    {
        $app = app();
        $originalRequest = $app->bound('request') ? $app->make('request') : null;

        try {
            $request = Request::create($path, 'GET');
            $request->headers->set('X-FlareWeber-Compile', '1');

            $response = $app->make(Kernel::class)->handle($request);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $type = strtolower($response->headers->get('Content-Type') ?: 'text/html');
            if (!str_contains($type, 'html')) {
                return null;
            }

            return $response->getContent() ?: null;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($originalRequest !== null) {
                $app->instance('request', $originalRequest);
            }
        }
    }
}
