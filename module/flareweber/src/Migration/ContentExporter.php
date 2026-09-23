<?php

namespace FlareWeber\Migration;

use Illuminate\Support\Facades\DB;

/**
 * Exports Microweber content (pages, posts, products) together with their
 * content_data, custom fields (prices included), categories and media rows.
 * Reads through Microweber's Content model when available and falls back to
 * the tables it documents. Ids are exported as "old ids" for re-linking.
 */
class ContentExporter
{
    public const CONTENT_TYPES = ['page', 'post', 'product'];

    /**
     * @return array{content: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    public function export(): array
    {
        return [
            'content' => $this->content(),
            'categories' => $this->categories(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function content(): array
    {
        $rows = DB::table('content')
            ->whereIn('content_type', self::CONTENT_TYPES)
            ->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $customFields = $this->customFields($id);

            $out[] = [
                'old_id' => $id,
                'content_type' => (string) $row->content_type,
                'subtype' => $row->subtype,
                'subtype_value' => $row->subtype_value,
                'parent' => (int) ($row->parent ?? 0),
                'title' => (string) $row->title,
                'url' => (string) $row->url,
                'description' => $row->description,
                'content' => $row->content,
                'content_body' => $row->content_body,
                'content_meta_title' => $row->content_meta_title,
                'content_meta_keywords' => $row->content_meta_keywords,
                'layout_file' => $row->layout_file,
                'active_site_template' => $row->active_site_template,
                'is_home' => (int) ($row->is_home ?? 0),
                'is_shop' => (int) ($row->is_shop ?? 0),
                'is_active' => (int) ($row->is_active ?? 1),
                'position' => $row->position ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
                'content_data' => $this->contentData($id),
                'custom_fields' => $customFields,
                'price' => $this->price($customFields),
                'category_ids' => $this->categoryIds($id),
                'media' => $this->media($id),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function contentData(int $id): array
    {
        return DB::table('content_data')
            ->where('rel_type', 'content')
            ->where('rel_id', $id)
            ->orderBy('id')
            ->pluck('field_value', 'field_name')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customFields(int $id): array
    {
        $fields = DB::table('custom_fields')
            ->where('rel_type', 'content')
            ->where('rel_id', $id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($fields as $field) {
            $values = DB::table('custom_fields_values')
                ->where('custom_field_id', (int) $field->id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('value')
                ->map(fn ($v) => (string) $v)
                ->all();

            $out[] = [
                'type' => (string) ($field->type ?? ''),
                'name' => (string) ($field->name ?? ''),
                'name_key' => (string) ($field->name_key ?? ''),
                'placeholder' => $field->placeholder,
                'options' => $field->options,
                'required' => (int) ($field->required ?? 0),
                'show_label' => (int) ($field->show_label ?? 0),
                'position' => (int) ($field->position ?? 0),
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $customFields
     */
    private function price(array $customFields): ?float
    {
        foreach ($customFields as $field) {
            if ($field['type'] === 'price' && isset($field['values'][0]) && is_numeric($field['values'][0])) {
                return (float) $field['values'][0];
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function categoryIds(int $id): array
    {
        return DB::table('categories_items')
            ->where('rel_type', 'content')
            ->where('rel_id', $id)
            ->orderBy('id')
            ->pluck('parent_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function media(int $id): array
    {
        $rows = DB::table('media')
            ->where('rel_type', 'content')
            ->where('rel_id', $id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'filename' => $this->portableFilename((string) $row->filename),
                'title' => $row->title,
                'description' => $row->description,
                'media_type' => $row->media_type ?: 'picture',
                'position' => (int) ($row->position ?? 0),
                'image_options' => $row->image_options,
            ];
        }

        return $out;
    }

    /**
     * Media filenames are stored with a {SITE_URL} placeholder or as absolute
     * URLs of this install; both become the placeholder form.
     */
    private function portableFilename(string $filename): string
    {
        $siteUrl = function_exists('site_url') ? rtrim((string) site_url(), '/') . '/' : null;
        if ($siteUrl !== null && str_starts_with($filename, $siteUrl)) {
            return '{SITE_URL}' . substr($filename, strlen($siteUrl));
        }

        return $filename;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        $rows = DB::table('categories')
            ->where(fn ($q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->orderBy('parent_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'old_id' => (int) $row->id,
                'parent_id' => (int) ($row->parent_id ?? 0),
                'title' => (string) $row->title,
                'url' => (string) $row->url,
                'description' => $row->description,
                'content' => $row->content,
                'data_type' => $row->data_type ?: 'category',
                'rel_type' => $row->rel_type ?: 'content',
                'rel_id' => (int) ($row->rel_id ?? 0),
                'position' => (int) ($row->position ?? 0),
                'is_active' => (int) ($row->is_active ?? 1),
                'is_hidden' => (int) ($row->is_hidden ?? 0),
                'category_meta_title' => $row->category_meta_title,
                'category_meta_description' => $row->category_meta_description,
            ];
        }

        return $out;
    }
}
