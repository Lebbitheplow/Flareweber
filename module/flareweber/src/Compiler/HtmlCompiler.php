<?php

namespace FlareWeber\Compiler;

/**
 * Pure HTML transformations applied to pages captured from the
 * Microweber frontend: strip authoring/editor chrome, rewrite links and
 * collect local asset URLs. No framework dependencies so it can be unit
 * tested in isolation.
 */
class HtmlCompiler
{
    /**
     * Remove Microweber admin/live-edit chrome from a rendered page.
     */
    public function clean(string $html): string
    {
        // Admin/live-edit scripts (admin panel, edit mode bootstraps, analytics of the editor).
        $html = preg_replace_callback(
            '#<script\b[^>]*>(.*?)</script>#is',
            function (array $m): string {
                $tag = $m[0];
                if (preg_match('#(/api/admin|/microweber|live_edit|liveEdit|edit_mode|admin\.js|adminApi)#i', $tag)) {
                    return '';
                }

                return $tag;
            },
            $html
        ) ?? $html;

        // Admin panel containers and live-edit overlays.
        $html = preg_replace('#<(div|section|aside)\b[^>]*(id|class)="[^"]*(mw-admin|admin-panel|overlayEditor|live-edit-overlay|edit-mode-toolbar)[^"]*"[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Microweber live-edit data attributes confuse the static runtime.
        $html = preg_replace('#\s(data-(?:id|name|type|text-type|field-type|is-modifiable|parent-name|role|wrap))[=:]"[^"]*"#i', '', $html) ?? $html;

        // Edit-mode inline handles left in markup.
        $html = preg_replace('#<!--\s*mw_(?:start|end).*?-->\s*#is', '', $html) ?? $html;

        // Comments are noise in the shipped bundle.
        $html = preg_replace('#<!--(?!\s*<!).{0,4000}?-->#s', '', $html) ?? $html;

        return trim($html);
    }

    /**
     * Rewrite same-host absolute URLs to site-relative paths and map
     * Microweber permalinks to compiled page paths.
     *
     * @param array<int, string> $pagePaths paths that exist in the compiled site, e.g. ['/', '/about']
     * @param array<int, string> $assetPaths local asset paths kept in the build, e.g. ['/userfiles/templates/x.css']
     */
    public function rewrite(string $html, string $baseUrl, array $pagePaths, array $assetPaths): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        $pagePaths = array_map(fn ($p) => rtrim($p, '/') ?: '/', $pagePaths);
        $pageMap = array_combine($pagePaths, $pagePaths);
        $assetMap = array_combine($assetPaths, $assetPaths);

        $html = preg_replace_callback(
            '#\s(href|src|poster)="([^"]+)"#i',
            function (array $m) use ($host, $pageMap, $assetMap): string {
                $attr = $m[1];
                $url = $m[2];

                if ($host !== '' && stripos($url, $host) !== false) {
                    $path = parse_url($url, PHP_URL_PATH) ?: '/';
                    if (isset($pageMap[$path])) {
                        return " {$attr}=\"" . ($pageMap[$path] === '/' ? './' : rtrim($pageMap[$path], '/') . '/') . '"';
                    }
                    if (isset($assetMap[$path])) {
                        return " {$attr}=\"" . ltrim($path, '/') . '"';
                    }

                    return " {$attr}=\"" . $path . '"';
                }

                if (str_starts_with($url, '/')) {
                    if (isset($pageMap[rtrim($url, '/') ?: '/'])) {
                        $clean = $pageMap[rtrim($url, '/') ?: '/'];

                        return " {$attr}=\"" . ($clean === '/' ? './' : rtrim($clean, '/') . '/') . '"';
                    }
                    if (isset($assetMap[$url])) {
                        return " {$attr}=\"" . ltrim($url, '/') . '"';
                    }

                    return " {$attr}=\"" . ltrim($url, '/') . '"';
                }

                return $m[0];
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Same-host absolute paths referenced by the page (candidate assets).
     *
     * @return array<int, string>
     */
    public function extractLocalAssetPaths(string $html, string $baseUrl): array
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        $paths = [];

        preg_match_all('#(?:href|src|poster)="([^"]+)"#i', $html, $matches);

        foreach ($matches[1] ?? [] as $url) {
            if (str_starts_with($url, '/')) {
                $paths[] = $url;
                continue;
            }
            if ($host !== '' && stripos($url, '//' . $host) !== false) {
                $path = parse_url($url, PHP_URL_PATH);
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Extension of a path, lowercase, without dot.
     */
    public function extension(string $path): string
    {
        return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
    }
}
