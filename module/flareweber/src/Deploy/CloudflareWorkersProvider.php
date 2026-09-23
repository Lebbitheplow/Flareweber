<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Cloudflare\ResourceProvisioner;
use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CloudflareWorkersProvider implements DeploymentProviderInterface
{
    public function __construct(private readonly HealthChecker $health = new HealthChecker())
    {
    }

    public function name(): string
    {
        return 'cloudflare_workers';
    }

    public function provision(Site $site): array
    {
        $connection = $this->connection($site);
        $client = CloudflareClient::forConnection($connection);

        $this->ensureWorkersSubdomain($connection, $client);

        $provisioner = new ResourceProvisioner($client);

        return $provisioner->provision(
            $site,
            $connection->account_id,
            $site->requiresD1(),
            $site->requiresR2()
        );
    }

    public function deploy(Site $site, CompiledSite $compiled, string $environment): DeploymentResult
    {
        $connection = $this->connection($site);
        $client = CloudflareClient::forConnection($connection);
        $this->ensureWorkersSubdomain($connection, $client);

        $preview = $environment === 'preview';
        $workerName = $this->workerName($site, $environment);

        if ($preview && $site->preview_worker_name !== $workerName) {
            $site->forceFill(['preview_worker_name' => $workerName])->save();
        }

        $uploader = new WorkerUploader($client, $connection->account_id);
        $steps = [];
        $log = [];

        try {
            $modulePath = $this->prepareModule($compiled, $environment, $log);
            $assetsDir = $compiled->directory . '/worker/assets';

            $versionId = $uploader->deploy(
                $workerName,
                $modulePath,
                $uploader->hasAssets($assetsDir) ? $assetsDir : null,
                $this->uploadMetadata($site, $environment)
            );
            $uploader->enableSubdomain($workerName);

            $steps['upload'] = ['status' => 'done', 'detail' => "Worker {$workerName} version {$versionId}"];
            $log[] = "Uploaded worker {$workerName} version {$versionId}";
        } catch (\Throwable $e) {
            $steps['upload'] = ['status' => 'failed', 'detail' => $e->getMessage()];

            return DeploymentResult::failed('Upload failed: ' . $e->getMessage(), $steps);
        }

        try {
            $names = $this->syncSecrets($site, $workerName, $uploader, $preview);
            $steps['secrets'] = ['status' => 'done', 'detail' => 'Set ' . implode(', ', $names)];
            $log[] = 'Configured worker secrets: ' . implode(', ', $names);
        } catch (\Throwable $e) {
            $steps['secrets'] = ['status' => 'failed', 'detail' => $e->getMessage()];

            return DeploymentResult::failed('Secrets failed: ' . $e->getMessage(), $steps, $versionId);
        }

        if ($preview) {
            $steps['seed'] = ['status' => 'skipped', 'detail' => 'Preview deploys never touch the production database'];
        } elseif (!$site->requiresD1()) {
            $steps['seed'] = ['status' => 'skipped', 'detail' => 'Site has no database'];
        } else {
            try {
                $count = $this->seedDatabase($site, $compiled, $client, $connection->account_id);
                $steps['seed'] = ['status' => 'done', 'detail' => "Applied {$count} statements to {$site->d1_database_name}"];
                $log[] = "Seeded D1 {$site->d1_database_name} ({$count} statements)";
            } catch (\Throwable $e) {
                $steps['seed'] = ['status' => 'failed', 'detail' => $e->getMessage()];

                return DeploymentResult::failed('D1 seed failed: ' . $e->getMessage(), $steps, $versionId);
            }
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $versionId,
            url: $site->fresh()->workersDevUrl($environment),
            log: $log,
            steps: $steps
        );
    }

    public function rollback(Site $site, Deployment $to): DeploymentResult
    {
        $connection = $this->connection($site);
        $client = CloudflareClient::forConnection($connection);
        $environment = $to->environment ?: 'production';
        $workerName = $this->workerName($site, $environment);
        $versionId = (string) $to->worker_version_id;

        if ($versionId === '') {
            return DeploymentResult::failed('Deployment has no worker version to roll back to.');
        }

        try {
            $client->post(
                "/accounts/{$connection->account_id}/workers/scripts/{$workerName}/deployments",
                [
                    'strategy' => 'percentage',
                    'versions' => [
                        ['version_id' => $versionId, 'percentage' => 100],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return DeploymentResult::failed($e->getMessage());
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $versionId,
            url: $site->workersDevUrl($environment),
            log: ["Rolled {$workerName} back to version {$versionId}"],
            steps: ['upload' => ['status' => 'done', 'detail' => "Re-pointed {$workerName} at version {$versionId}"]]
        );
    }

    public function verify(Site $site, string $url): array
    {
        return $this->health->check($url);
    }

    public function syncMedia(Site $site): ?array
    {
        if (!$site->requiresR2() || empty($site->r2_bucket_name)) {
            return null;
        }

        $connection = $this->connection($site);
        $syncer = new MediaSyncService(CloudflareClient::forConnection($connection), $connection->account_id);

        return $syncer->sync($site);
    }

    /**
     * Look up (or create) the account's workers.dev subdomain and cache it on
     * the connection; it is needed for SITE_URL and the health check.
     */
    private function ensureWorkersSubdomain(CloudflareConnection $connection, CloudflareClient $client): string
    {
        if ($connection->workers_subdomain) {
            return $connection->workers_subdomain;
        }

        $accountId = $connection->account_id;
        $response = $client->get("/accounts/{$accountId}/workers/subdomain", throwOnError: false);
        $subdomain = (string) ($response['result']['subdomain'] ?? '');

        if ($subdomain === '') {
            $candidate = Str::slug((string) ($connection->account_name ?: 'fw-' . substr($accountId, 0, 8)));
            $candidate = substr(trim($candidate, '-'), 0, 40) ?: 'fw-' . substr($accountId, 0, 8);

            $created = $client->put("/accounts/{$accountId}/workers/subdomain", ['subdomain' => $candidate]);
            $subdomain = (string) ($created['result']['subdomain'] ?? $candidate);
        }

        $connection->forceFill(['workers_subdomain' => $subdomain])->save();

        return $subdomain;
    }

    /**
     * Push runtime secrets to the Worker right after upload. Empty values are
     * never sent. Returns the names that were set.
     *
     * @return array<int, string>
     */
    private function syncSecrets(Site $site, string $workerName, WorkerUploader $uploader, bool $preview): array
    {
        $secrets = ['CART_SECRET' => $site->cartSecret()];

        if (!$preview && $site->requiresEcommerce()) {
            $stripeSecret = (string) $site->secret('stripe_secret_key');

            if ($stripeSecret === '') {
                throw new \RuntimeException(
                    'Ecommerce site is missing its Stripe secret key. Reconnect Stripe in site settings.'
                );
            }

            $secrets['STRIPE_SECRET_KEY'] = $stripeSecret;

            $webhookSecret = (string) $site->secret('stripe_webhook_secret');
            if ($webhookSecret !== '') {
                $secrets['STRIPE_WEBHOOK_SECRET'] = $webhookSecret;
            }
        }

        foreach ($secrets as $name => $value) {
            $uploader->putSecret($workerName, $name, $value);
        }

        return array_keys($secrets);
    }

    private function seedDatabase(Site $site, CompiledSite $compiled, CloudflareClient $client, string $accountId): int
    {
        $databaseId = $site->d1DatabaseId();

        if ($databaseId === null) {
            throw new \RuntimeException('The D1 database id is unknown; provisioning did not record it.');
        }

        $template = rtrim((string) config('flareweber.worker.template_path'), '/');
        $seeder = new D1Seeder($client);
        $count = 0;

        // 1. baseline schema (compiled copy, else the template's)
        $schema = File::exists($compiled->directory . '/worker/schema.sql')
            ? $compiled->directory . '/worker/schema.sql'
            : $template . '/schema.sql';
        if (File::exists($schema)) {
            $count += $seeder->applySql($accountId, $databaseId, File::get($schema));
        }

        // 2. incremental migrations, once each (schema_migrations bookkeeping)
        $count += count($seeder->applyMigrations($accountId, $databaseId, $template . '/migrations'));

        // 3. content upserts
        if (File::exists($compiled->directory . '/seed.sql')) {
            $count += $seeder->applySql($accountId, $databaseId, File::get($compiled->directory . '/seed.sql'));
        }

        return $count;
    }

    /**
     * Resolve the Worker module to upload: the prebuilt dist/worker.mjs when
     * it is at least as new as the TypeScript sources, else a fresh esbuild
     * bundle (falling back to the stale prebuilt file with a warning).
     *
     * @param array<int, string> $log
     */
    private function prepareModule(CompiledSite $compiled, string $environment, array &$log): string
    {
        $template = rtrim((string) config('flareweber.worker.template_path'), '/');
        $buildDir = dirname($compiled->directory) . '/deploy-' . $environment . '-' . $compiled->version;
        $prebuilt = $template . '/dist/worker.mjs';
        $bundler = new WorkerBundler($template);

        File::ensureDirectoryExists($buildDir);

        if (is_file($prebuilt) && !$bundler->sourceNewerThan($prebuilt)) {
            File::copy($prebuilt, $buildDir . '/worker.mjs');

            return $buildDir . '/worker.mjs';
        }

        $bundled = $bundler->bundle($buildDir);

        if ($bundled !== null) {
            $log[] = 'Bundled worker from source (dist/worker.mjs was missing or stale)';

            return $bundled;
        }

        if (is_file($prebuilt)) {
            $log[] = 'Warning: worker sources are newer than dist/worker.mjs and esbuild is unavailable; deploying the stale bundle';
            File::copy($prebuilt, $buildDir . '/worker.mjs');

            return $buildDir . '/worker.mjs';
        }

        throw new \RuntimeException(
            'Unable to bundle the Worker. Run "npm run bundle" in the worker template to produce dist/worker.mjs.'
        );
    }

    /**
     * Workers upload metadata (contract B): bindings, vars, compatibility.
     *
     * @return array<string, mixed>
     */
    private function uploadMetadata(Site $site, string $environment): array
    {
        $preview = $environment === 'preview';
        $site = $site->fresh();

        $bindings = [
            ['type' => 'assets', 'name' => 'ASSETS'],
        ];

        $d1Id = $site->d1DatabaseId();
        if (!$preview && $site->requiresD1() && $d1Id !== null) {
            $bindings[] = ['type' => 'd1', 'name' => 'DB', 'id' => $d1Id];
        }

        if ($site->requiresR2() && $site->r2_bucket_name) {
            $bindings[] = ['type' => 'r2_bucket', 'name' => 'MEDIA', 'bucket_name' => $site->r2_bucket_name];
        }

        $siteUrl = $preview ? $site->previewUrl() : $site->liveUrl();
        $features = [
            'ecommerce' => !$preview && $site->requiresEcommerce(),
            'forms' => !$preview && (bool) ($site->settings['forms'] ?? false),
        ];

        $vars = [
            'SITE_NAME' => (string) $site->name,
            'SITE_URL' => (string) $siteUrl,
            'SITE_DOMAIN' => (string) ($site->domain ?? ''),
            'CURRENCY' => strtoupper((string) ($site->settings['currency'] ?? 'USD')),
            'FEATURES' => (string) json_encode($features),
        ];

        foreach ($vars as $name => $text) {
            $bindings[] = ['type' => 'plain_text', 'name' => $name, 'text' => $text];
        }

        return [
            'main_module' => 'worker.mjs',
            'compatibility_date' => (string) config('flareweber.cloudflare.worker_compatibility_date', '2025-09-01'),
            'compatibility_flags' => ['nodejs_compat'],
            'bindings' => $bindings,
            'keep_bindings' => ['secret_text', 'secret_key'],
            'assets' => ['config' => WorkerUploader::ASSETS_CONFIG],
            'observability' => ['enabled' => true],
        ];
    }

    private function workerName(Site $site, string $environment): string
    {
        if ($environment === 'preview') {
            return $site->previewWorkerName();
        }

        return $site->worker_name ?: 'flareweber-' . $site->id;
    }

    private function connection(Site $site): CloudflareConnection
    {
        $connection = $site->cloudflareConnection;

        if ($connection === null) {
            throw new \RuntimeException('Site is not connected to a Cloudflare account.');
        }

        return $connection;
    }
}
