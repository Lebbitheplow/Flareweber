<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\ContentRepository;
use FlareWeber\Admin\D1OrdersRepository;
use FlareWeber\Admin\ProductRepository;
use FlareWeber\Admin\SiteResolver;
use FlareWeber\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function index(
        Request $request,
        ContentRepository $content,
        ProductRepository $products
    ): JsonResponse {
        $site = SiteResolver::fromRequest($request);
        if ($site === SiteResolver::NOT_FOUND) {
            return $this->notFound('site_not_found');
        }

        $latest = $site?->latestDeployment();

        return response()->json([
            'pages' => $content->count('page'),
            'products' => $products->count(),
            'orders_open' => $site ? $this->openOrders($site) : null,
            'low_stock' => $products->lowStock(),
            'unpublished_changes' => $content->changesSince($site?->published_at),
            'latest_deployment' => $latest ? [
                'id' => $latest->id,
                'version' => $latest->version,
                'environment' => $latest->environment,
                'status' => $latest->status,
                'url' => $latest->url,
                'created_at' => $latest->created_at?->toIso8601String(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ] : null,
            'site' => $site ? SiteResolver::present($site) : null,
        ]);
    }

    private function openOrders(Site $site): ?int
    {
        $orders = new D1OrdersRepository($site);
        if (!$orders->available()) {
            return null;
        }

        try {
            return $orders->openCount();
        } catch (\Throwable) {
            return null;
        }
    }
}
