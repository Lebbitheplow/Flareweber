<?php

namespace FlareWeber\Compiler;

/**
 * Copies every local asset referenced by the compiled pages into the static
 * bundle at the same root-relative path. Stylesheets are parsed for url()
 * and @import references, which are rewritten and bundled too.
 *
 * Assets that are not on disk but that the frontend serves dynamically
 * (Microweber thumbnails under /api/..., generated images) are fetched over
 * loopback and stored under /fw/assets/{hash}.{ext}; the caller rewrites
 * references using renames(). What cannot be found either way is reported:
 * missing() for markup references (fatal), missingInCss() for stylesheet
 * references (warnings, themes commonly reference files they do not ship).
 */
class AssetCollector
{
    public const DYNAMIC_PREFIX = '/fw/assets/';

    private const DENY_EXTENSIONS = ['php', 'phtml', 'phar', 'env', 'sqlite', 'sql', 'htaccess', 'ini', 'log', 'sh'];

    private const DENY_TOP_LEVEL = [
        'storage', 'vendor', 'config', 'bootstrap', 'src', 'database', 'routes', 'tests', 'app', 'resources',
        'node_modules', '.git',
    ];

    private const MIME_EXTENSIONS = [
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'image/svg+xml' => 'svg', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
        'text/css' => 'css', 'application/javascript' => 'js', 'text/javascript' => 'js', 'application/pdf' => 'pdf',
        'font/woff' => 'woff', 'font/woff2' => 'woff2', 'font/ttf' => 'ttf', 'video/mp4' => 'mp4', 'video/webm' => 'webm',
    ];

    /** @var array<string, string> root-relative path => bundled file */
    private array $copied = [];

    /** @var array<string, string> original path => bundled path for dynamically fetched assets */
    private array $renames = [];

    /** @var array<int, string> */
    private array $missing = [];

    /** @var array<int, string> */
    private array $missingInCss = [];

    /** @var array<string, true> */
    private array $processed = [];

    /**
     * @param array<int, string> $roots directories a root-relative path may resolve under, in priority order
     * @param (callable(string): (array{content_type: string, body: string}|null))|null $fetch loopback fetch for dynamic assets
     */
    public function __construct(
        private readonly UrlContext $ctx,
        private readonly UrlRewriter $rewriter,
        private readonly array $roots,
        private readonly string $assetsDir,
        private $fetch = null
    ) {
    }

    /**
     * @param array<int, string> $paths root-relative asset paths referenced from markup
     */
    public function collect(array $paths): void
    {
        $pending = array_map(fn (string $p) => ['path' => $p, 'css' => false], array_values($paths));

        while ($pending !== []) {
            ['path' => $path, 'css' => $fromCss] = array_shift($pending);

            if (isset($this->processed[$path])) {
                continue;
            }
            $this->processed[$path] = true;

            if ($this->ctx->mediaViaR2 && $this->ctx->isMediaPath($path)) {
                continue;
            }

            $source = $this->resolveOnDisk($path);
            if ($source === null) {
                if (!$this->fetchDynamic($path)) {
                    if ($fromCss) {
                        $this->missingInCss[] = $path;
                    } else {
                        $this->missing[] = $path;
                    }
                }

                continue;
            }

            $target = $this->target($path);

            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css') {
                $before = $this->rewriter->assets();
                $css = $this->rewriter->rewriteCss((string) file_get_contents($source), $path);
                file_put_contents($target, $css);

                foreach (array_diff($this->rewriter->assets(), $before) as $nested) {
                    $pending[] = ['path' => $nested, 'css' => true];
                }
            } else {
                copy($source, $target);
            }

            $this->copied[$path] = $target;
        }
    }

    /**
     * @return array<string, string>
     */
    public function copied(): array
    {
        return $this->copied;
    }

    /**
     * @return array<string, string> original path => bundled path
     */
    public function renames(): array
    {
        return $this->renames;
    }

    /**
     * @return array<int, string>
     */
    public function missing(): array
    {
        return array_values(array_unique($this->missing));
    }

    /**
     * @return array<int, string>
     */
    public function missingInCss(): array
    {
        return array_values(array_unique($this->missingInCss));
    }

    /**
     * Locate a root-relative path under one of the configured roots. The
     * resolved file must stay inside its root (no traversal) and must not be
     * server-side source or configuration.
     */
    public function resolveOnDisk(string $path): ?string
    {
        $relative = ltrim(rawurldecode((string) (parse_url($path, PHP_URL_PATH) ?: '')), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }

        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (in_array($ext, self::DENY_EXTENSIONS, true) || str_starts_with(basename($relative), '.')) {
            return null;
        }

        $top = explode('/', $relative, 2)[0];
        if (in_array($top, self::DENY_TOP_LEVEL, true)) {
            return null;
        }

        foreach ($this->roots as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) {
                continue;
            }

            $candidate = realpath($rootReal . '/' . $relative);
            if ($candidate === false || !is_file($candidate)) {
                continue;
            }

            if (!str_starts_with($candidate, rtrim($rootReal, '/') . '/')) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Ask the running frontend for the asset and store it under a static
     * path (the Worker routes /api/* to its own handlers, so dynamic
     * Microweber URLs cannot be kept as-is).
     */
    private function fetchDynamic(string $path): bool
    {
        if ($this->fetch === null) {
            return false;
        }

        $result = ($this->fetch)($path);
        if ($result === null || $result['body'] === '') {
            return false;
        }

        $ext = self::MIME_EXTENSIONS[$result['content_type']] ?? strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $bundled = self::DYNAMIC_PREFIX . substr(sha1($path), 0, 20) . ($ext !== '' ? '.' . $ext : '');

        file_put_contents($this->target($bundled), $result['body']);

        $this->copied[$bundled] = $this->target($bundled);
        $this->renames[$path] = $bundled;

        return true;
    }

    private function target(string $path): string
    {
        $target = rtrim($this->assetsDir, '/') . '/' . ltrim($path, '/');
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        return $target;
    }
}
