<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\ContentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagesController extends ApiController
{
    public function __construct(private readonly ContentRepository $content)
    {
    }

    public function pages(): JsonResponse
    {
        return response()->json($this->content->tree('page'));
    }

    public function posts(): JsonResponse
    {
        return response()->json($this->content->tree('post'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'title' => 'required|string|max:255',
            'parent_id' => 'nullable|integer|min:1',
        ]);

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId !== null && $this->content->find('page', $parentId) === null) {
            return response()->json([
                'error' => 'validation_failed',
                'errors' => ['parent_id' => ['Parent page does not exist.']],
            ], 422);
        }

        try {
            $id = $this->content->create('page', trim($data['title']), $parentId);
        } catch (\Throwable $e) {
            return $this->failed($e, 'create_failed');
        }

        return response()->json([
            'id' => $id,
            'edit_url' => $this->content->liveEditUrl($id),
            'url' => $this->content->link($id),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = $this->content->find('page', $id);
        if ($page === null) {
            return $this->notFound();
        }

        $data = $this->validated($request, [
            'title' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $fields = [];
        if (array_key_exists('title', $data)) {
            $fields['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = (bool) $data['is_active'];
        }

        try {
            $this->content->update($id, $fields);
        } catch (\Throwable $e) {
            return $this->failed($e, 'update_failed');
        }

        $fresh = $this->content->find('page', $id);

        return response()->json($fresh ? $this->content->present($fresh) : ['id' => $id]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($this->content->find('page', $id) === null) {
            return $this->notFound();
        }

        try {
            $this->content->delete($id);
        } catch (\Throwable $e) {
            return $this->failed($e, 'delete_failed');
        }

        return response()->json(['deleted' => true, 'id' => $id]);
    }
}
