<?php

namespace FlareWeber\Admin;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use MicroweberPackages\Product\Models\Product;

/**
 * Products for the admin SPA, backed by Microweber's Product model.
 * qty lives in content_data (`qty`, may be the string `nolimit`); price is
 * the `price` custom field written through CustomFieldPriceTrait.
 */
class ProductRepository
{
    public const LOW_STOCK_THRESHOLD = 3;

    private ?string $currency = null;

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function find(int $id): ?Product
    {
        return $this->baseQuery()->where('id', $id)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->baseQuery()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product) => $this->present($product))
            ->values()
            ->all();
    }

    /**
     * Products whose quantity is tracked (numeric) and at or below the threshold.
     *
     * @return array<int, array{id: int, title: string, qty: int}>
     */
    public function lowStock(int $threshold = self::LOW_STOCK_THRESHOLD): array
    {
        $low = [];

        foreach ($this->baseQuery()->orderBy('id')->get(['id', 'title']) as $product) {
            $qty = $this->normaliseQty($product->qty);
            if ($qty !== null && $qty <= $threshold) {
                $low[] = ['id' => (int) $product->id, 'title' => (string) $product->title, 'qty' => $qty];
            }
        }

        return $low;
    }

    public function create(string $title, ?float $price = null): int
    {
        $product = new Product();
        $product->title = $title;
        $product->is_active = 1;
        if ($price !== null) {
            $product->price = $price;
        }
        $product->save();

        return (int) $product->id;
    }

    /**
     * @param array{title?: string, is_active?: bool, price?: float, qty?: int|null} $fields
     */
    public function patch(Product $product, array $fields): void
    {
        if (array_key_exists('title', $fields)) {
            $product->title = $fields['title'];
        }
        if (array_key_exists('is_active', $fields)) {
            $product->is_active = $fields['is_active'] ? 1 : 0;
        }
        if (array_key_exists('price', $fields)) {
            $product->price = $fields['price'];
        }
        if (array_key_exists('qty', $fields)) {
            $product->setContentData([
                'qty' => $fields['qty'] === null ? 'nolimit' : (string) (int) $fields['qty'],
            ]);
        }

        // Always bump updated_at so "unpublished changes" sees qty/price edits
        // that only touch content_data / custom_fields.
        $product->updated_at = now();
        $product->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Product $product): array
    {
        $id = (int) $product->id;
        $sku = trim((string) $product->sku);

        return [
            'id' => $id,
            'title' => (string) $product->title,
            'price' => (float) $product->price,
            'currency' => $this->currency(),
            'qty' => $this->normaliseQty($product->qty),
            'sku' => $sku !== '' ? $sku : null,
            'image' => $this->image($id),
            'is_active' => (int) $product->is_active === 1,
            'url' => $this->link($id),
            'edit_url' => $this->editUrl($id),
            'updated_at' => $product->updated_at instanceof CarbonInterface
                ? $product->updated_at->toIso8601String()
                : null,
        ];
    }

    /**
     * Admin product editor: the `admin.product.edit` route registered by
     * MicroweberPackages\Product (resource `shop/product`), i.e.
     * /{admin}/shop/product/{id}/edit.
     */
    public function editUrl(int $id): string
    {
        if (Route::has('admin.product.edit')) {
            return route('admin.product.edit', $id);
        }

        return \admin_url('shop/product/' . $id . '/edit');
    }

    public function currency(): string
    {
        if ($this->currency === null) {
            $value = app()->option_manager->get('currency', 'payments');
            $this->currency = is_string($value) && $value !== '' ? strtoupper($value) : 'USD';
        }

        return $this->currency;
    }

    /**
     * Microweber stores `nolimit` (or nothing) for untracked stock; the API
     * exposes that as null.
     */
    public function normaliseQty(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 'nolimit') {
            return null;
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    private function baseQuery(): Builder
    {
        return Product::query()
            ->where(fn (Builder $q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0));
    }

    private function link(int $id): ?string
    {
        try {
            $link = app()->content_manager->link($id);
        } catch (\Throwable) {
            return null;
        }

        return is_string($link) && $link !== '' ? $link : null;
    }

    private function image(int $id): ?string
    {
        try {
            $picture = app()->media_manager->get_picture($id, 'content');
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($picture) || $picture === '') {
            return null;
        }

        return str_replace('{SITE_URL}', \site_url(), $picture);
    }
}
