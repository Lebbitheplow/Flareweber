<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\D1OrdersRepository;
use FlareWeber\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrdersController extends ApiController
{
    public function index(Site $site): JsonResponse
    {
        $orders = new D1OrdersRepository($site);
        if (!$orders->available()) {
            return response()->json(['error' => 'no_database'], 501);
        }

        try {
            return response()->json($orders->list());
        } catch (\Throwable $e) {
            return $this->failed($e, 'cloudflare_error', 502);
        }
    }

    public function update(Request $request, Site $site, int $id): JsonResponse
    {
        $orders = new D1OrdersRepository($site);
        if (!$orders->available()) {
            return response()->json(['error' => 'no_database'], 501);
        }

        $data = $this->validated($request, [
            'status' => ['required', 'string', Rule::in(D1OrdersRepository::UPDATABLE_STATUSES)],
        ]);

        try {
            $order = $orders->updateStatus($id, $data['status']);
        } catch (\Throwable $e) {
            return $this->failed($e, 'cloudflare_error', 502);
        }

        if ($order === null) {
            return $this->notFound();
        }

        return response()->json($order);
    }
}
