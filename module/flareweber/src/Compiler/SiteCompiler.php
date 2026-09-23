<?php

namespace FlareWeber\Compiler;

use DOMDocument;
use DOMElement;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns the running Microweber site into a static bundle for the Worker:
 * crawls the frontend over loopback HTTP, cleans and rewrites every page,
 * bundles assets, adds 404/thank-you/sitemap/robots, and emits the product
 * seed. Output layout follows contract D.
 */
class SiteCompiler
{
    public const SHIM_PATH = '/fw/store.js';

    public const JQUERY_PATH = '/fw/vendor/jquery.min.js';

    public function __construct(
        private readonly ProductExtractor $products,
        private readonly SeedGenerator $seeds,
        private readonly HtmlCompiler $html = new HtmlCompiler(),
        private readonly SitemapGenerator $sitemaps = new SitemapGenerator()
    ) {
    }

    public function compile(Site $site, bool $mediaViaR2 = false): CompiledSite
    {
        $buildPath = rtrim((string) config('flareweber.worker.build_path'), '/');
        $directory = $buildPath . '/' . $site->id . '/' . Str::uuid();
        $assetsDir = $directory . '/worker/assets';
        File::ensureDirectoryExists($assetsDir);
        File::ensureDirectoryExists($directory . '/worker/data');

        $ctx = UrlContext::fromBaseUrl((string) config('app.url'), $this->publicUrl($site), $mediaViaR2);
        $rewriter = new UrlRewriter($ctx);
        $maxPages = max(1, (int) config('flareweber.compiler.max_pages', 2000));
        $loopback = new LoopbackRenderer($ctx);
        $inProcess = new InProcessRenderer($ctx);

        $crawler = new Crawler(
            $loopback,
            $inProcess,
            $ctx,
            $this->html,
            $rewriter,
            $maxPages
        );
        $crawl = $crawler->crawl();
        $rewriter->setAliases($crawl->aliases);

        $features = [
            'ecommerce' => $site->requiresEcommerce(),
            'forms' => (bool) ($site->settings['forms'] ?? false),
        ];
        $shimTag = $this->installStoreShim($features, $assetsDir);

        $home = $crawl->pages['/'] ?? null;
        $placeholder = $home === null;

        $docs = $crawl->pages;
        if ($placeholder) {
            $docs['/'] = HtmlDocument::parse($this->html->standalonePage(
                (string) $site->name,
                'Coming soon',
                '<h1>' . e((string) $site->name) . '</h1><p>This site has no published home page yet. '
                . 'Create a home page in Microweber and publish again.</p>'
            ));
        }

        $docs['/404'] = $this->notFoundPage($crawl, $home, $site);
        $docs['/thank-you'] = $this->thankYouPage($home, $site);

        $missingVendor = [];
        $jquery = $this->installJquery($assetsDir, $missingVendor);

        foreach ($docs as $path => $doc) {
            $rewriter->rewriteDocument($doc, $path);
            // Injected after rewriting: the tag is already root-relative and
            // must not be treated as a page asset to resolve on disk.
            $needsJquery = $crawl->coreStripped[$path] ?? $crawl->coreStripped['/'] ?? false;
            if ($jquery !== null && $needsJquery) {
                $this->html->injectVendorScript($doc, $jquery);
            }
            if ($shimTag !== null) {
                $this->html->injectScripts($doc, $shimTag);
            }
        }

        $fetcher = $crawl->renderer === 'loopback' ? $loopback : $inProcess;
        $fetch = fn (string $path) => $fetcher->fetch($path);
        $collector = new AssetCollector($ctx, $rewriter, $this->assetRoots(), $assetsDir, $fetch);
        $collector->collect($rewriter->assets());
        $missing = $this->dropMissingSyntheticLinks($docs, $collector->missing());
        $renames = $collector->renames();

        foreach ($docs as $path => $doc) {
            $file = $path === '/404' ? '404.html' : PagePath::file($path);
            $target = $assetsDir . '/' . $file;
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $this->applyRenames(HtmlDocument::serialize($doc), $renames));
        }

        if ($renames !== []) {
            foreach ($collector->copied() as $path => $file) {
                if (str_ends_with($path, '.css')) {
                    File::put($file, $this->applyRenames((string) file_get_contents($file), $renames));
                }
            }
        }

        $routes = array_keys($crawl->pages);
        if ($placeholder) {
            $routes = ['/'];
        }

        $now = now();
        File::put($assetsDir . '/sitemap.xml', $this->sitemaps->sitemap($routes, $ctx, $now->toDateString()));
        File::put($assetsDir . '/robots.txt', $this->sitemaps->robots($ctx));

        $products = $features['ecommerce'] ? $this->products->products($ctx) : [];
        $this->writeSeed($site, $directory, $products, $now->toIso8601String());

        $active = array_values(array_filter($products, fn (array $p) => $p['is_active']));
        $invalidPrices = array_values(array_map(
            fn (array $p) => $p['id'],
            array_filter($active, fn (array $p) => (int) $p['price_cents'] <= 0)
        ));

        $manifest = [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'domain' => $site->domain,
            'public_url' => $ctx->publicUrl,
            'compiled_at' => $now->toIso8601String(),
            'features' => $features + ['media' => $site->requiresR2(), 'media_via_r2' => $mediaViaR2],
            'routes' => $routes,
            'files' => ['index.html', '404.html', 'thank-you/index.html', 'sitemap.xml', 'robots.txt'],
            'placeholder' => $placeholder,
            'renderer' => $crawl->renderer,
            'truncated' => $crawl->truncated,
            'product_count' => count($products),
            'active_product_count' => count($active),
            'invalid_price_product_ids' => $invalidPrices,
            'asset_count' => count($collector->copied()),
            'dynamic_asset_count' => count($renames),
            'missing_assets' => $missing,
            'missing_css_assets' => $collector->missingInCss(),
            'missing_vendor' => $missingVendor,
            'broken_links' => $crawl->broken,
            'log' => $crawl->log,
        ];

        File::put($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $hash = hash('sha256', json_encode($manifest) . $this->contentHash($assetsDir));

        return new CompiledSite($directory, substr($hash, 0, 16), $manifest);
    }

    /**
     * Prefer the frontend's own 404 page, else the home layout with a
     * not-found message, else a minimal standalone page.
     */
    private function notFoundPage(CrawlResult $crawl, ?DOMDocument $home, Site $site): DOMDocument
    {
        if ($crawl->notFound !== null) {
            return $crawl->notFound;
        }

        $fragment = '<section class="container fw-not-found" style="padding:48px 0">'
            . '<h1>Page not found</h1>'
            . '<p>The page you were looking for does not exist or has moved.</p>'
            . '<p><a href="/">Back to the home page</a></p></section>';

        if ($home !== null) {
            return $this->html->deriveFromLayout($home, 'Page not found', $fragment);
        }

        return HtmlDocument::parse($this->html->standalonePage((string) $site->name, 'Page not found', $fragment));
    }

    /**
     * Checkout landing page: home layout with an order summary block the
     * store shim fills from /api/orders/by-session/{id}.
     */
    private function thankYouPage(?DOMDocument $home, Site $site): DOMDocument
    {
        $fragment = '<section class="container fw-thank-you" style="padding:48px 0">'
            . '<h1>Thank you for your order</h1>'
            . '<p id="fw-order-status">Loading your order details...</p>'
            . '<div id="fw-order-summary"></div>'
            . '<p><a href="/">Continue shopping</a></p></section>';

        if ($home !== null) {
            return $this->html->deriveFromLayout($home, 'Thank you for your order', $fragment);
        }

        return HtmlDocument::parse($this->html->standalonePage((string) $site->name, 'Thank you for your order', $fragment));
    }

    /**
     * Stylesheet links the cleaner synthesized from mw.require() calls are
     * optional: drop them when the file is not on disk instead of failing
     * the publish. Returns the remaining (real) missing assets.
     *
     * @param array<string, DOMDocument> $docs
     * @param array<int, string> $missing
     * @return array<int, string>
     */
    private function dropMissingSyntheticLinks(array $docs, array $missing): array
    {
        $missingSet = array_fill_keys($missing, true);
        $forgiven = [];

        foreach ($docs as $doc) {
            foreach (iterator_to_array(HtmlDocument::xpath($doc)->query('//link[@data-fw-synth]') ?: []) as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }
                $path = PagePath::normalize((string) (parse_url($link->getAttribute('href'), PHP_URL_PATH) ?: ''));
                if (isset($missingSet[$path])) {
                    $forgiven[$path] = true;
                    HtmlDocument::remove($link);
                } else {
                    $link->removeAttribute('data-fw-synth');
                }
            }
        }

        return array_values(array_filter($missing, fn (string $p) => !isset($forgiven[$p])));
    }

    /**
     * Dynamically fetched assets live under /fw/assets; point every
     * reference to the original (dynamic) URL at the bundled copy.
     *
     * @param array<string, string> $renames
     */
    private function applyRenames(string $content, array $renames): string
    {
        if ($renames === []) {
            return $content;
        }

        return str_replace(array_keys($renames), array_values($renames), $content);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function writeSeed(Site $site, string $directory, array $products, string $publishedAt): void
    {
        $schema = rtrim((string) config('flareweber.worker.template_path'), '/') . '/schema.sql';
        if (is_file($schema)) {
            File::copy($schema, $directory . '/worker/schema.sql');
        }

        $seedSql = $this->seeds->generate($products, [
            'site_name' => (string) $site->name,
            'site_domain' => (string) ($site->domain ?? ''),
            'published_at' => $publishedAt,
            'sync_inventory' => ($site->settings['sync_inventory'] ?? true) ? '1' : '0',
        ]);

        File::put($directory . '/seed.sql', $seedSql);
        File::put($directory . '/worker/data/products.json', json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Copy the store shim to /fw/store.js and return the tags that load it,
     * or null when neither ecommerce nor forms is enabled.
     */
    private function installStoreShim(array $features, string $assetsDir): ?string
    {
        if (!$features['ecommerce'] && !$features['forms']) {
            return null;
        }

        $source = dirname(__DIR__, 2) . '/resources/js/store-shim.js';
        if (!is_file($source)) {
            return null;
        }

        File::ensureDirectoryExists($assetsDir . '/fw');
        File::copy($source, $assetsDir . self::SHIM_PATH);

        $config = json_encode(['ecommerce' => $features['ecommerce'], 'forms' => $features['forms']]);

        return '<script>window.FW_FEATURES=' . $config . ';</script>'
            . '<script src="' . self::SHIM_PATH . '" defer></script>';
    }

    /**
     * Microweber ships jQuery inside its apijs_combined bundle, which the
     * cleaner removes; templates still expect window.$. Copy the jQuery
     * build Microweber bundles to /fw/vendor/jquery.min.js and return that
     * path, or null (recording the gap) when no copy is on disk.
     *
     * @param array<int, string> $missingVendor
     */
    private function installJquery(string $assetsDir, array &$missingVendor): ?string
    {
        $candidates = [
            'userfiles/modules/microweber/components/jquery/jquery.min.js',
            'userfiles/modules/microweber/components/jquery/jquery-3.3.1.min.js',
            'userfiles/modules/microweber/components/jquery/jquery-3.1.0.min.js',
            'userfiles/modules/microweber/js/jquery-1.10.2.min.js',
        ];

        foreach ($candidates as $relative) {
            $source = base_path($relative);
            if (is_file($source)) {
                File::ensureDirectoryExists($assetsDir . '/fw/vendor');
                File::copy($source, $assetsDir . self::JQUERY_PATH);

                return self::JQUERY_PATH;
            }
        }

        $missingVendor[] = 'jquery';

        return null;
    }

    /**
     * Directories a root-relative asset URL may resolve under. Microweber
     * keeps userfiles at the app root; some installs serve from public/.
     *
     * @return array<int, string>
     */
    private function assetRoots(): array
    {
        $roots = [];
        foreach ([public_path(), base_path()] as $root) {
            if (is_dir($root)) {
                $roots[] = rtrim($root, '/');
            }
        }

        return array_values(array_unique($roots));
    }

    private function publicUrl(Site $site): ?string
    {
        if (method_exists($site, 'liveUrl')) {
            $url = $site->liveUrl();

            return is_string($url) && $url !== '' ? $url : null;
        }

        return $site->domain ? 'https://' . $site->domain : null;
    }

    private function contentHash(string $assetsDir): string
    {
        $hash = hash_init('sha256');
        $files = File::allFiles($assetsDir);
        usort($files, fn ($a, $b) => strcmp($a->getPathname(), $b->getPathname()));

        foreach ($files as $file) {
            hash_update($hash, $file->getRelativePathname());
            hash_update_file($hash, $file->getPathname());
        }

        return hash_final($hash);
    }
}
