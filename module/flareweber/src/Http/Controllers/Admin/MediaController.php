<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\MediaRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends ApiController
{
    private const MAX_UPLOAD_KB = 102400;

    public function __construct(private readonly MediaRepository $media)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->media->all());
    }

    public function store(Request $request): JsonResponse
    {
        $this->validated($request, [
            'file' => 'required|file|max:' . self::MAX_UPLOAD_KB,
        ]);

        try {
            $entry = $this->media->upload($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'validation_failed',
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        } catch (\Throwable $e) {
            return $this->failed($e, 'upload_failed');
        }

        return response()->json($entry, 201);
    }

    public function destroy(string $id): JsonResponse
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,512}$/', $id)) {
            return $this->notFound();
        }

        try {
            $deleted = $this->media->delete($id);
        } catch (\Throwable $e) {
            return $this->failed($e, 'delete_failed');
        }

        if (!$deleted) {
            return $this->notFound();
        }

        return response()->json(['deleted' => true, 'id' => $id]);
    }
}
