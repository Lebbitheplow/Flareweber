<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SiteController extends Controller
{
    public function index()
    {
        return response()->json(
            Site::with('cloudflareConnection')->latest()->get()
                ->map(fn (Site $site) => $this->present($site))
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'ecommerce' => 'boolean',
            'forms' => 'boolean',
            'media' => 'boolean',
            'template' => 'nullable|string',
            'cloudflare_connection_id' => 'nullable|exists:flare_cloudflare_connections,id',
        ]);

        $connection = CloudflareConnection::find($data['cloudflare_connection_id'] ?? null);

        $site = Site::create([
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
            'cloudflare_connection_id' => $connection?->id,
            'settings' => [
                'ecommerce' => (bool) ($data['ecommerce'] ?? false),
                'forms' => (bool) ($data['forms'] ?? false),
                'media' => $data['media'] ?? true,
                'template' => $data['template'] ?? 'dream',
            ],
        ]);

        return response()->json($this->present($site->fresh()), 201);
    }

    public function show(Site $site)
    {
        return response()->json($this->present($site));
    }

    public function update(Request $request, Site $site)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'settings' => 'sometimes|array',
        ]);

        $site->update($data);

        return response()->json($this->present($site->fresh()));
    }

    private function present(Site $site): array
    {
        $latest = $site->latestDeployment();

        return [
            'id' => $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'worker' => $site->worker_name,
            'cloudflare_connected' => $site->cloudflareConnection !== null,
            'ecommerce' => $site->requiresEcommerce(),
            'published_at' => $site->published_at?->toIso8601String(),
            'latest_deployment' => $latest ? [
                'version' => $latest->version,
                'status' => $latest->status,
                'url' => $latest->url,
                'created_at' => $latest->created_at->toIso8601String(),
            ] : null,
        ];
    }
}
