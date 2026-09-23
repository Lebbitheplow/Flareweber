<?php

namespace FlareWeber\Admin;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use MicroweberPackages\Content\Models\Content;
use RuntimeException;

/**
 * Pages and posts as seen by the admin SPA. Reads go through the Content
 * model; writes go through Microweber's own content manager helpers
 * (save_content / delete_content) so caches, events and slugs behave
 * exactly as they do in the Microweber admin.
 */
class ContentRepository
{
    public const TYPES = ['page', 'post'];

    private const CHANGE_TYPES = ['page', 'post', 'product'];

    private const MAX_DEPTH = 20;

    public function count(string $type): int
    {
        return $this->baseQuery($type)->count();
    }

    public function find(string $type, int $id): ?Content
    {
        return $this->baseQuery($type)->where('id', $id)->first();
    }

    /**
     * Nested tree of pages or posts, ordered by position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(string $type): array
    {
        $rows = $this->baseQuery($type)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'title', 'url', 'parent', 'is_active', 'updated_at', 'content_type', 'is_home']);

        $ids = array_flip($rows->pluck('id')->all());
        $children = [];
        $roots = [];

        foreach ($rows as $row) {
            $parent = (int) $row->parent;
            if ($parent > 0 && isset($ids[$parent]) && $parent !== (int) $row->id) {
                $children[$parent][] = $row;
            } else {
                $roots[] = $row;
            }
        }

        return array_map(fn (Content $row) => $this->node($row, $children, 0), $roots);
    }

    /**
     * Content rows edited after $since (or every row when $since is null).
     *
     * @return array<int, array{type: string, id: int, title: string, updated_at: ?string}>
     */
    public function changesSince(?CarbonInterface $since, int $limit = 200): array
    {
        $query = Content::query()
            ->whereIn('content_type', self::CHANGE_TYPES)
            ->where(fn (Builder $q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($since !== null) {
            $query->where('updated_at', '>', $since->copy()->utc()->format('Y-m-d H:i:s'));
        }

        return $query->get(['id', 'title', 'content_type', 'updated_at'])
            ->map(fn (Content $row) => [
                'type' => (string) $row->content_type,
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'updated_at' => $this->iso($row->updated_at),
            ])
            ->values()
            ->all();
    }

    public function create(string $type, string $title, ?int $parentId = null): int
    {
        $result = \save_content([
            'title' => $title,
            'content_type' => $type,
            'subtype' => $type === 'page' ? 'static' : 'post',
            'parent' => $parentId ?? 0,
            'is_active' => 1,
        ]);

        return $this->idFromSaveResult($result);
    }

    /**
     * @param array{title?: string, is_active?: bool} $fields
     */
    public function update(int $id, array $fields): void
    {
        $data = ['id' => $id];

        if (array_key_exists('title', $fields)) {
            $data['title'] = $fields['title'];
        }
        if (array_key_exists('is_active', $fields)) {
            $data['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        if (count($data) === 1) {
            return;
        }

        $this->idFromSaveResult(\save_content($data));
    }

    /**
     * Moves the content to Microweber's trash (is_deleted = 1), which is what
     * the Microweber admin does on delete.
     */
    public function delete(int $id): void
    {
        \delete_content(['id' => $id]);
    }

    public function link(int $id): ?string
    {
        try {
            $link = app()->content_manager->link($id);
        } catch (\Throwable) {
            return null;
        }

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * Live-edit URL for a page or post. Microweber 2.0.20 resolves the legacy
     * `{url}?editmode=y` link to `{admin}/live-edit?url={page url}` (see
     * FrontendController), so this returns that target directly.
     */
    public function liveEditUrl(int $id): ?string
    {
        $link = $this->link($id);
        if ($link === null) {
            return null;
        }

        return \admin_url('live-edit') . '?url=' . rawurlencode($link);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(Content $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'url' => $this->link((int) $row->id),
            'parent_id' => (int) $row->parent,
            'is_active' => (int) $row->is_active === 1,
            'updated_at' => $this->iso($row->updated_at),
            'content_type' => (string) $row->content_type,
            'edit_url' => $this->liveEditUrl((int) $row->id),
        ];
    }

    private function baseQuery(string $type): Builder
    {
        return Content::query()
            ->where('content_type', $type)
            ->where(fn (Builder $q) => $q->whereNull('is_deleted')->orWhere('is_deleted', 0));
    }

    /**
     * @param array<int, array<int, Content>> $children
     * @return array<string, mixed>
     */
    private function node(Content $row, array $children, int $depth): array
    {
        $item = $this->present($row);
        $item['children'] = $depth >= self::MAX_DEPTH
            ? []
            : array_map(
                fn (Content $child) => $this->node($child, $children, $depth + 1),
                $children[(int) $row->id] ?? []
            );

        return $item;
    }

    private function idFromSaveResult(mixed $result): int
    {
        if (is_array($result) && isset($result['error'])) {
            throw new RuntimeException('Microweber refused to save content: ' . $result['error']);
        }

        if (is_array($result) && isset($result['id'])) {
            $result = $result['id'];
        }

        if (!is_numeric($result) || (int) $result <= 0) {
            throw new RuntimeException('Microweber did not return a content id.');
        }

        return (int) $result;
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return $value ? (string) $value : null;
    }
}
