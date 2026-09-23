<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\ProductRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductsController extends ApiController
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->products->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'title' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        try {
            $id = $this->products->create(
                trim($data['title']),
                isset($data['price']) ? (float) $data['price'] : null
            );
        } catch (\Throwable $e) {
            return $this->failed($e, 'create_failed');
        }

        return response()->json([
            'id' => $id,
            'edit_url' => $this->products->editUrl($id),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = $this->products->find($id);
        if ($product === null) {
            return $this->notFound();
        }

        $data = $this->validated($request, [
            'title' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
            'price' => 'sometimes|nullable|numeric|min:0',
            'qty' => 'sometimes|nullable|integer|min:0',
        ]);

        $fields = [];
        if (array_key_exists('title', $data)) {
            $fields['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = (bool) $data['is_active'];
        }
        if ($request->has('price')) {
            $fields['price'] = isset($data['price']) ? (float) $data['price'] : 0.0;
        }
        if ($request->has('qty')) {
            $fields['qty'] = isset($data['qty']) ? (int) $data['qty'] : null;
        }

        try {
            $this->products->patch($product, $fields);
        } catch (\Throwable $e) {
            return $this->failed($e, 'update_failed');
        }

        $fresh = $this->products->find($id);

        return response()->json($fresh ? $this->products->present($fresh) : ['id' => $id]);
    }
}
