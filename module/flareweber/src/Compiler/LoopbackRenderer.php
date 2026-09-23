<?php

namespace FlareWeber\Compiler;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Renders frontend pages by requesting them over HTTP from the running
 * Microweber instance (config app.url). Requests carry no cookies, so pages
 * are rendered exactly as an anonymous visitor sees them: no live-edit
 * chrome, no drafts, and each page gets a fresh PHP process so Microweber's
 * per-process page constants are never stale.
 */
class LoopbackRenderer implements RendererInterface
{
    public const HEADER = 'X-FlareWeber-Compile';

    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly UrlContext $ctx,
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 5
    ) {
    }

    public function render(string $requestPath): RenderedPage
    {
        $path = str_starts_with($requestPath, '/') ? $requestPath : '/' . $requestPath;
        $url = $this->ctx->origin() . $path;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = $this->request($url);
            } catch (ConnectionException $e) {
                return RenderedPage::failed('connect: ' . $e->getMessage());
            } catch (\Throwable $e) {
                return RenderedPage::failed('request: ' . $e->getMessage());
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                $location = $response->header('Location');
                if ($location === '') {
                    return new RenderedPage($status, null, '', null);
                }

                $next = $this->resolveRedirect($location, $path);
                if ($next === null) {
                    // Off-site redirect: not a page of this site.
                    return new RenderedPage($status, null, '', null);
                }

                $path = $next;
                $url = $this->ctx->origin() . $path;

                continue;
            }

            return new RenderedPage(
                $status,
                $response->body(),
                (string) $response->header('Content-Type'),
                $path
            );
        }

        return RenderedPage::failed('too many redirects');
    }

    /**
     * Fetch a non-page resource (dynamic thumbnails, generated images) over
     * loopback. Returns null unless the frontend answers 200 with a
     * non-HTML body.
     *
     * @return array{content_type: string, body: string}|null
     */
    public function fetch(string $requestPath, int $maxBytes = 26214400): ?array
    {
        $rendered = $this->render($requestPath);
        if ($rendered->status !== 200 || $rendered->html === null || $rendered->isHtml()) {
            return null;
        }
        if (strlen($rendered->html) > $maxBytes) {
            return null;
        }

        return ['content_type' => strtolower(trim(explode(';', $rendered->contentType)[0])), 'body' => $rendered->html];
    }

    private function request(string $url): Response
    {
        return Http::withoutRedirecting()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->withHeaders([
                self::HEADER => '1',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en',
                'User-Agent' => 'FlareWeber-Compiler/1.0',
            ])
            ->withOptions(['cookies' => false])
            ->get($url);
    }

    /**
     * Same-origin redirects yield the next root-relative request path;
     * anything else returns null.
     */
    private function resolveRedirect(string $location, string $currentPath): ?string
    {
        $location = trim($location);

        if (preg_match('#^https?://#i', $location)) {
            if (!$this->ctx->isLocal($location)) {
                return null;
            }
            $parts = parse_url($location) ?: [];

            return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        if (str_starts_with($location, '//')) {
            return null;
        }

        if (str_starts_with($location, '/')) {
            return $location;
        }

        $dir = rtrim(dirname((string) (parse_url($currentPath, PHP_URL_PATH) ?: '/')), '/');

        return $dir . '/' . $location;
    }
}
