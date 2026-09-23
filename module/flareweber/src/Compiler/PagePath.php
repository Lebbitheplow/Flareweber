<?php

namespace FlareWeber\Compiler;

/**
 * Canonical page path handling shared by the crawler, rewriter and writer.
 *
 * Canonical form: percent-decoded, single slashes, no trailing slash, root
 * is "/". Paginated listings map to "{path}/page/{n}". Output links always
 * end with "/" (root is "/") and files are written to "{path}/index.html".
 */
final class PagePath
{
    public static function normalize(string $path): string
    {
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Split a local URL into its canonical page path, a leftover query string
     * (pagination removed) and the fragment. Pagination parameters are folded
     * into the path.
     *
     * @return array{path: string, query: string, fragment: string, request: string}
     */
    public static function fromUrl(string $localUrl): array
    {
        $fragment = '';
        if (($hash = strpos($localUrl, '#')) !== false) {
            $fragment = substr($localUrl, $hash);
            $localUrl = substr($localUrl, 0, $hash);
        }

        $query = '';
        if (($q = strpos($localUrl, '?')) !== false) {
            $query = substr($localUrl, $q + 1);
            $localUrl = substr($localUrl, 0, $q);
        }

        $path = self::normalize($localUrl);
        $request = $path;
        $params = [];
        parse_str($query, $params);

        $folded = false;
        foreach (array_keys($params) as $name) {
            if (!self::isPaginationParam((string) $name) || is_array($params[$name])) {
                continue;
            }

            $n = (int) $params[$name];
            unset($params[$name]);

            if (!$folded && $n >= 2) {
                $request = $path . '?' . $name . '=' . $n;
                $path = ($path === '/' ? '' : $path) . '/page/' . $n;
            }
            $folded = true;
        }

        $leftover = http_build_query($params);

        return [
            'path' => $path,
            'query' => $leftover,
            'fragment' => $fragment,
            'request' => $request === '' ? '/' : $request,
        ];
    }

    /**
     * "page", "current_page" and Microweber's per-module variants such as
     * "current_page3373826946" all paginate a listing.
     */
    public static function isPaginationParam(string $name): bool
    {
        foreach (UrlContext::PAGINATION_PARAMS as $base) {
            if (preg_match('/^' . preg_quote($base, '/') . '\d*$/', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Root-relative link for a canonical page path ("/about" -> "/about/").
     */
    public static function output(string $path): string
    {
        $path = self::normalize($path);

        return $path === '/' ? '/' : $path . '/';
    }

    /**
     * File path (relative to the assets dir) where the page is written.
     */
    public static function file(string $path): string
    {
        $path = self::normalize($path);

        return $path === '/' ? 'index.html' : ltrim($path, '/') . '/index.html';
    }

    public static function hasExtension(string $path): bool
    {
        $last = basename($path);

        return $last !== '' && str_contains($last, '.') && pathinfo($last, PATHINFO_EXTENSION) !== '';
    }

    /**
     * True when the first path segment matches one of the skipped prefixes
     * (whole-segment, case-sensitive comparison).
     *
     * @param array<int, string> $skipSegments
     */
    public static function isSkipped(string $path, array $skipSegments): bool
    {
        $first = explode('/', ltrim($path, '/'), 2)[0] ?? '';

        return $first !== '' && in_array($first, $skipSegments, true);
    }
}
