<?php

namespace FlareWeber\Compiler;

use Illuminate\Support\Str;

/**
 * Reads shop products through Microweber's own Product model (content rows
 * with content_type = product). Price comes from the "price" custom field,
 * stock from content_data "qty" (null or "nolimit" = unlimited) and images
 * from the media table with {SITE_URL} placeholders resolved to root-relative
 * paths. Output follows contract E.
 */
class ProductExtractor
{
    public const PRODUCT_MODEL = '\\MicroweberPackages\\Product\\Models\\Product';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(UrlContext $ctx): array
    {
        $model = self::PRODUCT_MODEL;
        if (!class_exists($model)) {
            return [];
        }

        try {
            $rows = $model::query()
                ->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $currency = $this->currency();
        $rewriter = new UrlRewriter($ctx);
        $slugs = [];
        $products = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $images = $this->images($row, $ctx, $rewriter);

            $products[] = [
                'id' => $id,
                'title' => trim((string) $row->title),
                'slug' => $this->uniqueSlug((string) $row->url, (string) $row->title, $id, $slugs),
                'description' => $this->plainText((string) ($row->description ?: $row->content_body ?: $row->content)),
                'price_cents' => $this->priceCents($row),
                'currency' => $currency,
                'quantity' => $this->quantity($row),
                'sku' => $this->sku($row),
                'image' => $images[0] ?? null,
                'images' => $images,
                'categories' => $this->categories($id),
                'is_active' => (int) $row->is_active === 1,
                'variants' => [],
            ];
        }

        return $products;
    }

    private function currency(): string
    {
        try {
            $value = app()->option_manager->get('currency', 'payments');
        } catch (\Throwable) {
            $value = null;
        }

        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : 'USD';
    }

    private function priceCents(object $row): int
    {
        try {
            $price = $row->price;
        } catch (\Throwable) {
            $price = 0;
        }

        if (is_string($price)) {
            $price = str_replace(',', '.', trim($price));
        }

        return (int) round(((float) $price) * 100);
    }

    private function quantity(object $row): int
    {
        try {
            $qty = $row->qty;
        } catch (\Throwable) {
            $qty = null;
        }

        if ($qty === null || $qty === '' || (is_string($qty) && strtolower($qty) === 'nolimit')) {
            return -1;
        }

        return max(0, (int) $qty);
    }

    private function sku(object $row): ?string
    {
        try {
            $sku = trim((string) $row->sku);
        } catch (\Throwable) {
            $sku = '';
        }

        return $sku === '' ? null : $sku;
    }

    /**
     * @return array<int, string>
     */
    private function images(object $row, UrlContext $ctx, UrlRewriter $rewriter): array
    {
        try {
            $media = $row->media()->get();
        } catch (\Throwable) {
            return [];
        }

        $images = [];
        foreach ($media as $item) {
            // The model accessor substitutes {SITE_URL} with the current
            // request origin, which is meaningless on the CLI; use the stored value.
            $raw = method_exists($item, 'getRawOriginal') ? $item->getRawOriginal('filename') : null;
            $filename = trim((string) ($raw ?? $item->filename ?? ''));
            if ($filename === '') {
                continue;
            }

            $filename = str_replace(['{SITE_URL}', '%7BSITE_URL%7D'], '/', $filename);
            $filename = preg_replace('#^/+#', '/', $filename) ?? $filename;

            if (preg_match('#^https?://#i', $filename) && !$ctx->isLocal($filename)) {
                $userfiles = strpos($filename, '/userfiles/');
                if ($userfiles === false) {
                    $images[] = $filename;

                    continue;
                }
                $filename = substr($filename, $userfiles);
            }

            $local = $rewriter->resolve($filename, '/');
            if ($local === null) {
                continue;
            }

            $path = PagePath::normalize((string) (parse_url($local, PHP_URL_PATH) ?: ''));
            if ($path === '/') {
                continue;
            }

            $images[] = $ctx->mediaTarget($path);
        }

        return array_values(array_unique($images));
    }

    /**
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    private function categories(int $contentId): array
    {
        if (!function_exists('get_categories_for_content')) {
            return [];
        }

        try {
            $rows = get_categories_for_content($contentId);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $id = (int) ($row['id'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $name = trim((string) ($row['title'] ?? ''));
            $slug = trim((string) ($row['url'] ?? ''));
            $out[] = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug !== '' ? $slug : (Str::slug($name) ?: 'category-' . $id),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, true> $taken
     */
    private function uniqueSlug(string $url, string $title, int $id, array &$taken): string
    {
        $slug = basename(trim($url, '/'));
        if ($slug === '' || $slug === '.') {
            $slug = Str::slug($title);
        }
        if ($slug === '') {
            $slug = 'product-' . $id;
        }

        if (isset($taken[$slug])) {
            $slug .= '-' . $id;
        }
        $taken[$slug] = true;

        return $slug;
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
