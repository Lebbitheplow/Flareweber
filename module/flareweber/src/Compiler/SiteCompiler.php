<?php

namespace FlareWeber\Compiler;

use FlareWeber\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SiteCompiler
{
    public function __construct(
        private readonly PageExtractor $pages,
        private readonly ProductExtractor $products,
        private readonly SeedGenerator $seeds
    ) {
    }

    public function compile(Site $site): CompiledSite
    {
        $buildPath = rtrim(config('flareweber.worker.build_path'), '/');
        $directory = $buildPath . '/' . $site->id . '/' . Str::uuid();

        File::ensureDirectoryExists($directory . '/worker/assets');

        $baseUrl = rtrim((string) config('app.url'), '/');
        $compiler = new HtmlCompiler();
        $maxPages = (int) config('flareweber.compiler.max_pages', 50);

        $pages = $this->pages->extract($baseUrl, $compiler, $maxPages);
        if ($pages === []) {
            $pages = $this->placeholderPages($site);
        }

        $products = $site->requiresEcommerce() ? $this->products->products() : [];

        $assetPaths = $this->collectAssetPaths($pages, $baseUrl, $compiler);
        $copiedAssets = $this->copyAssets($baseUrl, $assetPaths, $directory . '/worker/assets');

        foreach ($pages as $path => $html) {
            $final = $compiler->rewrite($html, $baseUrl, array_keys($pages), array_keys($copiedAssets));
            $target = $directory . '/worker/assets/' . $this->assetPathForPage($path);
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $final);
        }

        File::copy(
            config('flareweber.worker.template_path') . '/schema.sql',
            $directory . '/worker/schema.sql'
        );

        $seedSql = $this->seeds->generate($products, [
            'site_name' => $site->name,
            'site_domain' => $site->domain ?? '',
            'published_at' => now()->toIso8601String(),
        ]);
        File::put($directory . '/seed.sql', $seedSql);
        File::put($directory . '/worker/data/products.json', json_encode($products, JSON_PRETTY_PRINT));

        $manifest = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'domain' => $site->domain,
            'compiled_at' => now()->toIso8601String(),
            'features' => [
                'ecommerce' => $site->requiresEcommerce(),
                'forms' => (bool) ($site->settings['forms'] ?? false),
                'media' => $site->requiresR2(),
            ],
            'routes' => array_keys($pages),
            'product_count' => count($products),
            'asset_count' => count($copiedAssets),
        ];

        File::put($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $hash = hash('sha256', json_encode($manifest) . serialize($pages));

        return new CompiledSite($directory, substr($hash, 0, 16), $manifest);
    }

    /**
     * @return array<int, string>
     */
    private function collectAssetPaths(array $pages, string $baseUrl, HtmlCompiler $compiler): array
    {
        $paths = [];
        $skip = ['/', '/favicon.ico', '/robots.txt', '/manifest.json', '/manifest.webmanifest'];

        foreach ($pages as $html) {
            foreach ($compiler->extractLocalAssetPaths($html, $baseUrl) as $path) {
                $ext = $compiler->extension($path);
                if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'avif'], true)
                    && !in_array($path, $skip, true)) {
                    $paths[] = $path;
                }
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * Assets live on disk next to the bundled app (public/userfiles/...),
     * so they are copied directly instead of being fetched over HTTP.
     *
     * @param array<int, string> $assetPaths
     * @return array<string, string> source path => local file
     */
    private function copyAssets(string $baseUrl, array $assetPaths, string $assetsDir): array
    {
        $copied = [];

        foreach ($assetPaths as $path) {
            $source = $this->resolveOnDisk($path);
            if ($source === null) {
                continue;
            }

            $target = rtrim($assetsDir, '/') . '/' . ltrim($path, '/');
            File::ensureDirectoryExists(dirname($target));
            File::copy($source, $target);
            $copied[$path] = $target;
        }

        return $copied;
    }

    private function resolveOnDisk(string $path): ?string
    {
        $public = rtrim(public_path(), '/');
        $relative = ltrim($path, '/');

        // Microweber may serve versioned/thumbnail variants such as
        // /userfiles/storage-cache/... which also live under public/.
        $candidate = $public . '/' . $relative;
        $real = realpath($candidate);

        if ($real === false || !is_file($real) || !str_starts_with($real, $public . '/')) {
            return null;
        }

        return $real;
    }

    private function assetPathForPage(string $pagePath): string
    {
        $clean = trim($pagePath, '/');

        return $clean === '' ? 'index.html' : $clean . '/index.html';
    }

    private function placeholderPages(Site $site): array
    {
        $name = e($site->name);

        return [
            '/' => "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
                . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
                . "<title>{$name}</title></head><body><h1>{$name}</h1>"
                . "<p>Compiled by FlareWeber. The bundled Microweber frontend rendered no "
                . "pages during compile; create or publish content and publish again "
                . "to capture real content.</p></body></html>",
        ];
    }
}
