<?php

namespace FlareWeber\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Recreates exported Microweber content through Microweber's Eloquent
 * content models (Page, Post, Product), which run the same save hooks the
 * admin uses: content_data, custom fields (price), category links and
 * media rows. Categories are created first so id mapping is available.
 */
class ContentImporter
{
    private const MODELS = [
        'page' => '\\MicroweberPackages\\Page\\Models\\Page',
        'post' => '\\MicroweberPackages\\Post\\Models\\Post',
        'product' => '\\MicroweberPackages\\Product\\Models\\Product',
    ];

    private const CATEGORY_MODEL = '\\MicroweberPackages\\Category\\Models\\Category';

    private const COPY_COLUMNS = [
        'subtype', 'subtype_value', 'title', 'url', 'description', 'content', 'content_body',
        'content_meta_title', 'content_meta_keywords', 'layout_file', 'active_site_template',
        'is_home', 'is_shop', 'is_active',
    ];

    /** @var array<int, int> old content id => new id */
    private array $contentIds = [];

    /** @var array<int, int> old category id => new id */
    private array $categoryIds = [];

    /**
     * @param array{content?: array<int, array<string, mixed>>, categories?: array<int, array<string, mixed>>} $data
     * @return array{content: int, categories: int, skipped: int}
     */
    public function import(array $data): array
    {
        foreach (['page', 'post', 'product'] as $type) {
            if (!class_exists(self::MODELS[$type])) {
                throw new \RuntimeException('Microweber content models are not available; cannot import content.');
            }
        }

        $content = array_values(array_filter(
            $data['content'] ?? [],
            fn ($row) => is_array($row) && in_array($row['content_type'] ?? '', array_keys(self::MODELS), true)
        ));
        $categories = array_values(array_filter($data['categories'] ?? [], 'is_array'));

        // Pass 1: content rows in parent-first order (parents get new ids before children).
        $created = 0;
        $skipped = 0;
        foreach ($this->parentFirst($content) as $row) {
            $id = $this->createContent($row);
            if ($id === null) {
                $skipped++;
            } else {
                $created++;
            }
        }

        // Pass 2: categories need their owning page id, then category links.
        $categoryCount = 0;
        foreach ($this->parentFirstCategories($categories) as $row) {
            if ($this->createCategory($row) !== null) {
                $categoryCount++;
            }
        }

        foreach ($content as $row) {
            $this->linkCategories($row);
        }

        if (function_exists('clearcache')) {
            try {
                clearcache();
            } catch (\Throwable) {
                // Cache reset is best effort.
            }
        }

        return ['content' => $created, 'categories' => $categoryCount, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createContent(array $row): ?int
    {
        $type = (string) $row['content_type'];
        $model = self::MODELS[$type];

        $attributes = [];
        foreach (self::COPY_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $attributes[$column] = $row[$column];
            }
        }

        $attributes['title'] = trim((string) ($attributes['title'] ?? ''));
        if ($attributes['title'] === '') {
            return null;
        }

        $attributes['content_type'] = $type;
        $attributes['url'] = $this->uniqueUrl((string) ($row['url'] ?? ''), $attributes['title']);
        $attributes['parent'] = $this->contentIds[(int) ($row['parent'] ?? 0)] ?? 0;
        $attributes['is_active'] = (int) ($row['is_active'] ?? 1);
        $attributes['is_home'] = (int) ($row['is_home'] ?? 0) === 1 && !$this->homeExists() ? 1 : 0;

        $contentData = is_array($row['content_data'] ?? null) ? $row['content_data'] : [];
        if ($contentData !== []) {
            $attributes['content_data'] = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $contentData);
        }

        if ($type === 'product' && isset($row['price']) && is_numeric($row['price'])) {
            $attributes['price'] = (float) $row['price'];
        }

        $entity = new $model();
        $entity->fill($attributes);

        foreach ($this->mediaRows($row) as $media) {
            $entity->addMedia($media);
        }

        $entity->save();
        $newId = (int) $entity->id;
        $this->contentIds[(int) $row['old_id']] = $newId;

        $this->createCustomFields($newId, is_array($row['custom_fields'] ?? null) ? $row['custom_fields'] : []);

        return $newId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, array<string, mixed>>
     */
    private function mediaRows(array $row): array
    {
        $out = [];
        foreach (is_array($row['media'] ?? null) ? $row['media'] : [] as $media) {
            $filename = trim((string) ($media['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }
            $out[] = [
                'filename' => $filename,
                'title' => $media['title'] ?? null,
                'description' => $media['description'] ?? null,
                'media_type' => (string) ($media['media_type'] ?? 'picture'),
                'position' => (int) ($media['position'] ?? 0),
                'image_options' => $media['image_options'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Custom fields other than the price (which the model already wrote).
     *
     * @param array<int, array<string, mixed>> $fields
     */
    private function createCustomFields(int $contentId, array $fields): void
    {
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? '');
            $name = trim((string) ($field['name'] ?? ''));
            if ($type === 'price' || $name === '') {
                continue;
            }

            $fieldId = DB::table('custom_fields')->insertGetId([
                'rel_type' => 'content',
                'rel_id' => $contentId,
                'type' => $type,
                'name' => $name,
                'name_key' => (string) ($field['name_key'] ?: \Illuminate\Support\Str::slug($name, '-')),
                'placeholder' => $field['placeholder'] ?? null,
                'options' => is_string($field['options'] ?? null) ? $field['options'] : null,
                'required' => (int) ($field['required'] ?? 0),
                'show_label' => (int) ($field['show_label'] ?? 0),
                'position' => (int) ($field['position'] ?? 0),
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_values((array) ($field['values'] ?? [])) as $position => $value) {
                DB::table('custom_fields_values')->insert([
                    'custom_field_id' => $fieldId,
                    'value' => (string) $value,
                    'position' => $position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function createCategory(array $row): ?int
    {
        $model = self::CATEGORY_MODEL;
        if (!class_exists($model)) {
            return null;
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $category = new $model();
        $category->fill([
            'title' => $title,
            'url' => (string) ($row['url'] ?? ''),
            'description' => $row['description'] ?? null,
            'content' => $row['content'] ?? null,
            'data_type' => (string) ($row['data_type'] ?? 'category'),
            'rel_type' => 'content',
            'rel_id' => $this->contentIds[(int) ($row['rel_id'] ?? 0)] ?? 0,
            'parent_id' => $this->categoryIds[(int) ($row['parent_id'] ?? 0)] ?? 0,
            'position' => (int) ($row['position'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'is_hidden' => (int) ($row['is_hidden'] ?? 0),
            'category_meta_title' => $row['category_meta_title'] ?? null,
            'category_meta_description' => $row['category_meta_description'] ?? null,
        ]);
        $category->save();

        $this->categoryIds[(int) $row['old_id']] = (int) $category->id;

        return (int) $category->id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function linkCategories(array $row): void
    {
        $newId = $this->contentIds[(int) ($row['old_id'] ?? 0)] ?? null;
        if ($newId === null) {
            return;
        }

        foreach ((array) ($row['category_ids'] ?? []) as $oldCategoryId) {
            $categoryId = $this->categoryIds[(int) $oldCategoryId] ?? null;
            if ($categoryId === null) {
                continue;
            }

            $exists = DB::table('categories_items')
                ->where('rel_type', 'content')
                ->where('rel_id', $newId)
                ->where('parent_id', $categoryId)
                ->exists();

            if (!$exists) {
                DB::table('categories_items')->insert([
                    'rel_type' => 'content',
                    'rel_id' => $newId,
                    'parent_id' => $categoryId,
                ]);
            }
        }
    }

    private function uniqueUrl(string $url, string $title): string
    {
        $slug = trim($url, '/');
        if ($slug === '') {
            $slug = \Illuminate\Support\Str::slug($title) ?: 'content';
        }

        $candidate = $slug;
        for ($i = 2; DB::table('content')->where('url', $candidate)->exists(); $i++) {
            $candidate = $slug . '-' . $i;
        }

        return $candidate;
    }

    private function homeExists(): bool
    {
        return DB::table('content')
            ->where('is_home', 1)
            ->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->exists();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function parentFirst(array $rows): array
    {
        return $this->topologicalOrder($rows, 'old_id', 'parent');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function parentFirstCategories(array $rows): array
    {
        return $this->topologicalOrder($rows, 'old_id', 'parent_id');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function topologicalOrder(array $rows, string $idKey, string $parentKey): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row[$idKey] ?? 0)] = $row;
        }

        $ordered = [];
        $placed = [];
        $remaining = $byId;

        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $id => $row) {
                $parent = (int) ($row[$parentKey] ?? 0);
                if ($parent === 0 || isset($placed[$parent]) || !isset($byId[$parent])) {
                    $ordered[] = $row;
                    $placed[$id] = true;
                    unset($remaining[$id]);
                    $progress = true;
                }
            }
            if (!$progress) {
                // Cycle: append whatever is left with parents unresolved.
                foreach ($remaining as $row) {
                    $ordered[] = $row;
                }
                break;
            }
        }

        return $ordered;
    }
}
