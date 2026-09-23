<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SiteController extends Controller
{
    /** Settings the SPA may write (contract G). Everything else is internal. */
    private const SETTINGS_ALLOWLIST = [
        'ecommerce', 'forms', 'media', 'template', 'contact_email', 'sync_inventory', 'skip_validation',
    ];

    private const SETTINGS_RULES = [
        'settings.ecommerce' => 'sometimes|boolean',
        'settings.forms' => 'sometimes|boolean',
        'settings.media' => 'sometimes|boolean',
        'settings.template' => 'sometimes|nullable|string|max:120',
        'settings.contact_email' => 'sometimes|nullable|email|max:190',
        'settings.sync_inventory' => 'sometimes|boolean',
        'settings.skip_validation' => 'sometimes|boolean',
    ];

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
            'ecommerce' => 'sometimes|boolean',
            'forms' => 'sometimes|boolean',
            'media' => 'sometimes|boolean',
            'template' => 'nullable|string|max:120',
            'contact_email' => 'nullable|email|max:190',
            'cloudflare_connection_id' => 'nullable|integer|exists:flare_cloudflare_connections,id',
        ] + self::SETTINGS_RULES);

        $connection = isset($data['cloudflare_connection_id'])
            ? CloudflareConnection::find($data['cloudflare_connection_id'])
            : $this->latestConnectedConnection();

        $settings = $this->filterSettings((array) ($data['settings'] ?? []));

        $site = Site::create([
            'user_id' => $request->user()?->id,
            'name' => $data['name'],
            'cloudflare_connection_id' => $connection?->id,
            'settings' => array_merge([
                'ecommerce' => (bool) ($data['ecommerce'] ?? $settings['ecommerce'] ?? false),
                'forms' => (bool) ($data['forms'] ?? $settings['forms'] ?? false),
                'media' => (bool) ($data['media'] ?? $settings['media'] ?? true),
                'template' => $data['template'] ?? $settings['template'] ?? 'default',
                'contact_email' => $data['contact_email'] ?? $settings['contact_email'] ?? null,
                'sync_inventory' => (bool) ($settings['sync_inventory'] ?? true),
            ], array_diff_key($settings, array_flip(['ecommerce', 'forms', 'media', 'template', 'contact_email']))),
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
            'cloudflare_connection_id' => 'sometimes|nullable|integer|exists:flare_cloudflare_connections,id',
            'settings' => 'sometimes|array',
        ] + self::SETTINGS_RULES);

        $update = [];

        if (array_key_exists('name', $data)) {
            $update['name'] = $data['name'];
        }

        if (array_key_exists('cloudflare_connection_id', $data)) {
            $update['cloudflare_connection_id'] = $data['cloudflare_connection_id'];
        }

        if (array_key_exists('settings', $data)) {
            $update['settings'] = array_merge($site->settings ?? [], $this->filterSettings((array) $data['settings']));
        }

        if ($update !== []) {
            $site->forceFill($update)->save();
        }

        return response()->json($this->present($site->fresh()));
    }

    /**
     * Keep only allow-listed settings keys from client input.
     *
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function filterSettings(array $incoming): array
    {
        return array_intersect_key($incoming, array_flip(self::SETTINGS_ALLOWLIST));
    }

    private function latestConnectedConnection(): ?CloudflareConnection
    {
        return CloudflareConnection::query()
            ->where('status', 'connected')
            ->where('account_id', '!=', '')
            ->orderByDesc('id')
            ->first();
    }

    /** Contract G site JSON. Manifests and secrets are never included. */
    private function present(Site $site): array
    {
        $latest = $site->latestDeployment();
        $connection = $site->cloudflareConnection;
        $settings = $site->settings ?? [];

        return [
            'id' => $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'worker' => $site->worker_name,
            'cloudflare_connected' => $connection !== null && $connection->isConnected(),
            'cloudflare_connection_id' => $site->cloudflare_connection_id,
            'stripe_connected' => $site->stripeMethod() !== null,
            'stripe_method' => $site->stripeMethod(),
            'stripe_account_id' => $settings['stripe_account_id'] ?? null,
            'settings' => $this->publicSettings($settings),
            'ecommerce' => $site->requiresEcommerce(),
            'published_at' => $site->published_at?->toIso8601String(),
            'live_url' => $site->liveUrl(),
            'preview_url' => $site->previewUrl(),
            'resources' => [
                'worker' => $site->worker_name,
                'preview_worker' => $site->preview_worker_name,
                'd1' => $site->d1_database_name,
                'r2' => $site->r2_bucket_name,
                'workers_dev_url' => $site->workersDevUrl(),
                'zone_id' => $settings['zone_id'] ?? null,
            ],
            'latest_deployment' => $latest ? $this->presentDeployment($latest) : null,
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Settings exposed to the SPA: the editable allowlist plus a few
     * read-only status flags. Internal ids and manifests stay server-side.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function publicSettings(array $settings): array
    {
        $visible = array_merge(self::SETTINGS_ALLOWLIST, [
            'currency', 'stripe_connect_ready', 'stripe_charges_enabled', 'stripe_livemode',
        ]);

        return array_intersect_key($settings, array_flip($visible));
    }

    private function presentDeployment(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'version' => $deployment->version,
            'environment' => $deployment->environment,
            'status' => $deployment->status,
            'url' => $deployment->url,
            'created_at' => $deployment->created_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
        ];
    }
}
