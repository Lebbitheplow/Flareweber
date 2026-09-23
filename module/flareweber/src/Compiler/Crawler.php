<?php

namespace FlareWeber\Compiler;

use DOMDocument;

/**
 * Breadth-first crawl of the Microweber frontend starting at "/". Every
 * internal link on every captured page is followed (pagination included),
 * pages are deduplicated by canonical path (case-sensitive, percent-decoded)
 * and links that answer with a non-200 status are recorded as broken.
 */
class Crawler
{
    public const SKIP_SEGMENTS = [
        'admin', 'api', 'apijs', 'apijs_combined', 'login', 'logout', 'register', 'forgot-password',
        'cart', 'checkout', 'account', 'profile', 'search', 'preview', 'live_edit', 'edit', 'editmode',
        'userfiles', 'microweber', 'livewire', 'admin-livewire-components', 'flareweber', 'rss', 'feed',
        'sitemap.xml', 'robots.txt', 'thank-you', 'vendor', 'storage', 'proc',
    ];

    private RendererInterface $renderer;

    private bool $usingFallback = false;

    public function __construct(
        RendererInterface $primary,
        private readonly ?RendererInterface $fallback,
        private readonly UrlContext $ctx,
        private readonly HtmlCompiler $compiler,
        private readonly UrlRewriter $rewriter,
        private readonly int $maxPages = 2000,
        private readonly array $skipSegments = self::SKIP_SEGMENTS
    ) {
        $this->renderer = $primary;
    }

    public function crawl(): CrawlResult
    {
        $result = new CrawlResult();

        $queue = [['path' => '/', 'request' => '/', 'from' => null, 'nav' => false]];
        $seen = ['/' => true];

        while ($queue !== []) {
            if (count($result->pages) >= $this->maxPages) {
                $result->truncated = true;
                $result->log[] = 'Page limit of ' . $this->maxPages . ' reached; ' . count($queue) . ' links not crawled.';
                break;
            }

            $item = array_shift($queue);
            $rendered = $this->render($item['request'], $result);

            if (!$rendered->ok()) {
                if ($item['from'] !== null && !$rendered->unreachable()) {
                    $result->broken[] = [
                        'from' => $item['from'],
                        'to' => $item['path'],
                        'status' => $rendered->status,
                        'nav' => $item['nav'],
                    ];
                } elseif ($rendered->unreachable()) {
                    $result->log[] = 'Could not render ' . $item['request'] . ': ' . $rendered->error;
                }

                continue;
            }

            $canonical = $item['path'];
            if ($rendered->finalPath !== null) {
                $final = PagePath::fromUrl($rendered->finalPath)['path'];
                if ($final !== $canonical) {
                    $result->aliases[$canonical] = $final;
                    $canonical = $final;
                }
            }

            if (isset($result->pages[$canonical])) {
                continue;
            }

            $doc = HtmlDocument::parse((string) $rendered->html);
            if ($this->compiler->clean($doc)) {
                $result->coreStripped[$canonical] = true;
            }
            $result->pages[$canonical] = $doc;

            foreach ($this->compiler->navLinks($doc) as $href) {
                $target = $this->localPage($href, $canonical);
                if ($target !== null) {
                    $result->navTargets[$target['path']] = true;
                }
            }

            foreach ($this->compiler->links($doc) as $href) {
                $target = $this->localPage($href, $canonical);
                if ($target === null || isset($seen[$target['path']])) {
                    continue;
                }

                $seen[$target['path']] = true;
                $queue[] = [
                    'path' => $target['path'],
                    'request' => $target['request'],
                    'from' => $canonical,
                    'nav' => isset($result->navTargets[$target['path']]),
                ];
            }
        }

        $result->notFound = $this->captureNotFound($result);
        $result->renderer = $this->usingFallback ? 'in-process' : 'loopback';

        return $result;
    }

    /**
     * Resolve a link to a crawlable page: local, no file extension, not in a
     * skipped section. Returns the canonical path plus the request path
     * (which keeps pagination query strings).
     *
     * @return array{path: string, request: string}|null
     */
    public function localPage(string $href, string $fromPath): ?array
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(mailto|tel|javascript|data|sms):#i', $href)) {
            return null;
        }

        $local = $this->rewriter->resolve($href, $fromPath);
        if ($local === null) {
            return null;
        }

        $parts = PagePath::fromUrl($local);
        $path = $parts['path'];

        if (PagePath::hasExtension($path) || PagePath::isSkipped($path, $this->skipSegments)) {
            return null;
        }

        return ['path' => $path, 'request' => $parts['request']];
    }

    private function render(string $requestPath, CrawlResult $result): RenderedPage
    {
        $rendered = $this->renderer->render($requestPath);

        if ($rendered->unreachable() && !$this->usingFallback && $this->fallback !== null) {
            $result->log[] = 'Loopback render failed (' . $rendered->error . '); falling back to in-process rendering.';
            $this->renderer = $this->fallback;
            $this->usingFallback = true;
            $rendered = $this->renderer->render($requestPath);
        }

        return $rendered;
    }

    /**
     * Ask the frontend for a path that cannot exist; when it answers with a
     * themed HTML 404 page that page becomes the site's 404.
     */
    private function captureNotFound(CrawlResult $result): ?DOMDocument
    {
        $probe = '/fw-missing-' . bin2hex(random_bytes(6));
        $rendered = $this->render($probe, $result);

        if ($rendered->status !== 404 || $rendered->html === null || !$rendered->isHtml()) {
            return null;
        }

        if (!preg_match('#<html\b#i', $rendered->html) || !preg_match('#<body\b#i', $rendered->html)) {
            return null;
        }

        $doc = HtmlDocument::parse($rendered->html);
        $this->compiler->clean($doc);

        return $doc;
    }
}
