<?php

namespace FlareWeber\Compiler;

use Illuminate\Support\Facades\DB;

/**
 * Reads published shop items out of Microweber's content tables.
 *
 * Microweber stores products in the shared `content` table (is_shop = 1);
 * prices, stock and images live in `content_data` as field_name/field_value
 * rows. All queries are defensive: missing tables or fields degrade to
 * empty output instead of failing the build.
 */
class ProductExtractor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(): array
    {
        try {
            $rows = DB::table('content')
                ->where('is_shop', 1)
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->get(['id', 'title', 'url', 'description', 'content']);
        } catch (\Throwable) {
            return [];
        }

        $products = [];
        foreach ($rows as $row) {
            $fields = $this->fields((int) $row->id);
            $image = $fields['thumbnail_image'] ?? $fields['image'] ?? $fields['poster'] ?? null;
            $price = (float) ($fields['price'] ?? 0);

            $products[] = [
                'id' => (int) $row->id,
                'slug' => $this->slug($row->url, (int) $row->id),
                'title' => (string) $row->title,
                'description' => $this->plainText((string) ($row->description ?: $row->content)),
                'image' => $image ? $this->normalizeImage($image) : null,
                'price_cents' => (int) round($price * 100),
                'currency' => strtoupper($fields['currency'] ?? 'USD'),
                'quantity' => (int) ($fields['num_in_stock'] ?? $fields['quantity'] ?? 0),
                'categories' => $this->categoriesFor((int) $row->id),
            ];
        }

        return $products;
    }

    /**
     * @return array<string, string> field_name => field_value
     */
    private function fields(int $contentId): array
    {
        try {
            return DB::table('content_data')
                ->where('rel_id', $contentId)
                ->whereIn('rel_type', ['product', null])
                ->pluck('field_value', 'field_name')
                ->map(fn ($v) => (string) $v)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function categoriesFor(int $contentId): array
    {
        try {
            return DB::table('categories_items')
                ->join('categories', 'categories.id', '=', 'categories_items.category_id')
                ->where('categories_items.item_id', $contentId)
                ->pluck('categories.name')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function slug(?string $url, int $id): string
    {
        $slug = trim((string) $url, '/');

        return $slug !== '' ? $slug : 'product-' . $id;
    }

    private function normalizeImage(string $image): string
    {
        $path = parse_url($image, PHP_URL_PATH);
        if (is_string($path) && str_starts_with($path, '/')) {
            return ltrim($path, '/');
        }

        return ltrim($image, '/');
    }

    private function plainText(string $html): string
    {
        $text = strip_tags($html);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
