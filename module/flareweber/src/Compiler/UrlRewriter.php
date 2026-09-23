<?php

namespace FlareWeber\Compiler;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Rewrites every URL in a rendered page (or a CSS file) to the root-relative
 * form the static Worker serves. Pure: no framework or filesystem access.
 *
 * Local URLs are those that are root-relative, document-relative, or absolute
 * with the exact local origin (scheme + host + port). Everything else is left
 * untouched. Asset references are reported through a sink so the caller can
 * bundle them.
 */
class UrlRewriter
{
    private const LINK_ATTRS = ['href', 'src', 'poster', 'data-src', 'action', 'data'];

    private const SRCSET_ATTRS = ['srcset', 'data-srcset'];

    private const META_URL_PROPS = ['og:image', 'og:image:url', 'og:image:secure_url', 'og:url', 'twitter:image', 'twitter:url'];

    /** @var array<string, true> */
    private array $assets = [];

    /** @var array<string, string> requested page path => canonical page path (redirects) */
    private array $aliases = [];

    public function __construct(private readonly UrlContext $ctx)
    {
    }

    /**
     * @param array<string, string> $aliases
     */
    public function setAliases(array $aliases): void
    {
        $this->aliases = $aliases;
    }

    /**
     * Resolve a URL found on $pagePath to a local root-relative URL (path,
     * query and fragment kept) or null when it is external or not http(s).
     */
    public function resolve(string $url, string $pagePath = '/'): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            if (!$this->ctx->isLocal($url)) {
                return null;
            }

            $parts = parse_url($url);
            $path = $parts['path'] ?? '/';
            $path = $path === '' ? '/' : $path;

            return $path
                . (isset($parts['query']) ? '?' . $parts['query'] : '')
                . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        }

        if (str_starts_with($url, '//')) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (str_starts_with($url, '?')) {
            return ($pagePath === '/' ? '/' : PagePath::normalize($pagePath)) . $url;
        }

        return $this->mergeRelative($url, $pagePath);
    }

    /**
     * Rewrite one URL. $kind is "link" for navigation targets (href/action/
     * canonical) or "asset" for everything that loads a file.
     */
    public function rewriteUrl(string $url, string $pagePath, string $kind = 'asset'): string
    {
        $local = $this->resolve($url, $pagePath);
        if ($local === null) {
            return $url;
        }

        $path = (string) (parse_url($local, PHP_URL_PATH) ?: '/');

        if ($kind === 'link' && !PagePath::hasExtension($path) && !str_starts_with($path, '/userfiles/')) {
            $parts = PagePath::fromUrl($local);
            $parts['path'] = $this->aliases[$parts['path']] ?? $parts['path'];

            return PagePath::output($parts['path'])
                . ($parts['query'] !== '' ? '?' . $parts['query'] : '')
                . $parts['fragment'];
        }

        $path = PagePath::normalize($path);
        $this->recordAsset($path);

        return $this->ctx->mediaTarget($path);
    }

    /**
     * Rewrite every URL-bearing attribute, style block and inline style.
     */
    public function rewriteDocument(DOMDocument $doc, string $pagePath): void
    {
        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//*[@href or @src or @poster or @data-src or @action or @data or @srcset or @data-srcset or @style]') ?: [] as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }

            $this->rewriteElement($el, $pagePath);
        }

        foreach ($xpath->query('//style') ?: [] as $style) {
            $style->textContent = $this->rewriteCss($style->textContent, $pagePath);
        }

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $script->textContent = $this->rewriteStructuredData($script->textContent);
        }

        foreach ($xpath->query('//meta[@property or @name]') ?: [] as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $prop = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            if (!in_array(strtolower($prop), self::META_URL_PROPS, true)) {
                continue;
            }
            $content = $meta->getAttribute('content');
            if ($content === '') {
                continue;
            }
            $kind = str_ends_with(strtolower($prop), 'url') ? 'link' : 'asset';
            $rewritten = $this->rewriteUrl($content, $pagePath, $kind);
            if ($rewritten !== $content) {
                $meta->setAttribute('content', $kind === 'link' ? $this->ctx->absolute($rewritten) : $rewritten);
            }
        }
    }

    /**
     * Rewrite url(...) and @import references in CSS. $cssPath is the
     * root-relative path of the stylesheet (or the page for inline CSS).
     */
    public function rewriteCss(string $css, string $cssPath): string
    {
        $css = preg_replace_callback(
            '#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
            function (array $m) use ($cssPath): string {
                $target = trim($m[2]);
                if ($target === '' || str_starts_with($target, 'data:') || str_starts_with($target, '#')) {
                    return $m[0];
                }

                return 'url(' . $m[1] . $this->rewriteUrl($target, $cssPath, 'asset') . $m[1] . ')';
            },
            $css
        );

        $css = preg_replace_callback(
            '#@import\s+(["\'])([^"\']+)\1#i',
            fn (array $m): string => '@import ' . $m[1] . $this->rewriteUrl($m[2], $cssPath, 'asset') . $m[1],
            (string) $css
        );

        return (string) $css;
    }

    /**
     * JSON-LD blocks embed absolute URLs of the local origin; point them at
     * the public site (or make them root-relative when it is unknown).
     * Handles both raw and JSON-escaped ("http:\/\/") slashes.
     */
    public function rewriteStructuredData(string $json): string
    {
        $origin = $this->ctx->origin();
        $public = $this->ctx->publicUrl ?? '';

        $json = str_replace($origin, $public, $json);

        return str_replace(str_replace('/', '\\/', $origin), str_replace('/', '\\/', $public), $json);
    }

    public function rewriteSrcset(string $srcset, string $pagePath): string
    {
        $out = [];
        foreach (preg_split('/,(?=\s*\S+(?:\s+[^,]+)?\s*(?:,|$))/', $srcset) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $candidate, 2) ?: [$candidate];
            $bits[0] = $this->rewriteUrl($bits[0], $pagePath, 'asset');
            $out[] = implode(' ', $bits);
        }

        return implode(', ', $out);
    }

    /**
     * Root-relative source paths of every local asset seen so far
     * (before any /media/ mapping), sorted and unique.
     *
     * @return array<int, string>
     */
    public function assets(): array
    {
        $paths = array_keys($this->assets);
        sort($paths);

        return $paths;
    }

    public function resetAssets(): void
    {
        $this->assets = [];
    }

    private function rewriteElement(DOMElement $el, string $pagePath): void
    {
        $tag = strtolower($el->tagName);

        foreach (self::LINK_ATTRS as $attr) {
            if (!$el->hasAttribute($attr)) {
                continue;
            }
            if ($attr === 'data' && $tag !== 'object') {
                continue;
            }
            if ($attr === 'action' && $tag !== 'form') {
                continue;
            }

            $value = $el->getAttribute($attr);
            if ($value === '') {
                continue;
            }

            $kind = $this->kindFor($tag, $attr, $el);
            $rewritten = $this->rewriteUrl($value, $pagePath, $kind);

            if ($tag === 'link' && strtolower($el->getAttribute('rel')) === 'canonical' && $rewritten !== $value) {
                $rewritten = $this->ctx->absolute($rewritten);
            }

            if ($rewritten !== $value) {
                $el->setAttribute($attr, $rewritten);
            }
        }

        foreach (self::SRCSET_ATTRS as $attr) {
            if ($el->hasAttribute($attr) && $el->getAttribute($attr) !== '') {
                $el->setAttribute($attr, $this->rewriteSrcset($el->getAttribute($attr), $pagePath));
            }
        }

        if ($el->hasAttribute('style') && str_contains($el->getAttribute('style'), 'url(')) {
            $el->setAttribute('style', $this->rewriteCss($el->getAttribute('style'), $pagePath));
        }
    }

    private function kindFor(string $tag, string $attr, DOMElement $el): string
    {
        if ($attr === 'href') {
            if ($tag === 'link') {
                $rel = strtolower($el->getAttribute('rel'));

                return in_array($rel, ['canonical', 'alternate', 'next', 'prev', 'home', 'index'], true) ? 'link' : 'asset';
            }

            return in_array($tag, ['a', 'area', 'base'], true) ? 'link' : 'asset';
        }

        return $attr === 'action' ? 'link' : 'asset';
    }

    private function recordAsset(string $path): void
    {
        if ($path === '/' || $this->ctx->isMediaPath($path) && $this->ctx->mediaViaR2) {
            return;
        }

        $this->assets[$path] = true;
    }

    /**
     * RFC 3986 relative reference merge against the directory of $pagePath
     * (pages are requested without a trailing slash, so "/blog/post" resolves
     * relative to "/blog/"), followed by dot-segment removal.
     */
    private function mergeRelative(string $ref, string $pagePath): string
    {
        $base = PagePath::normalize((string) (parse_url($pagePath, PHP_URL_PATH) ?: '/'));
        $dir = $base === '/' ? '' : dirname($base);
        $dir = $dir === '/' || $dir === '\\' || $dir === '.' ? '' : $dir;

        $suffix = '';
        if (preg_match('/^([^?#]*)(.*)$/', $ref, $m)) {
            $ref = $m[1];
            $suffix = $m[2];
        }

        $segments = explode('/', $dir . '/' . $ref);
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out) . (str_ends_with($ref, '/') && $out !== [] ? '/' : '') . $suffix;
    }
}
