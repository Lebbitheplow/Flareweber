<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Cloudflare\ResourceProvisioner;
use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class CloudflareWorkersProvider implements DeploymentProviderInterface
{
    public function name(): string
    {
        return 'cloudflare_workers';
    }

    public function provision(Site $site): array
    {
        $connection = $this->connection($site);
        $provisioner = new ResourceProvisioner(CloudflareClient::forConnection($connection));

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
        $workerName = $this->workerName($site, $environment);

        $modulePath = $this->prepareModule($compiled, $environment);
        $assetsDir = $compiled->directory . '/worker/assets';

        $uploader = new WorkerUploader($client, $connection->account_id);
        $hasAssets = $uploader->hasAssets($assetsDir);

        try {
            $workerVersionId = $uploader->deploy(
                $workerName,
                $modulePath,
                $hasAssets ? $assetsDir : null,
                $this->uploadMetadata($site, $hasAssets)
            );
            $uploader->enableSubdomain($workerName);
        } catch (\Throwable $e) {
            return DeploymentResult::failed($e->getMessage());
        }

        $log = ['uploaded worker version ' . $workerVersionId];

        $databaseId = $site->settings['d1_database_id'] ?? null;
        if ($databaseId && File::exists($compiled->directory . '/seed.sql')) {
            try {
                $seeder = new D1Seeder($client);
                $seeder->applySql($connection->account_id, $databaseId, File::get($compiled->directory . '/schema.sql'));
                $seeder->applySql($connection->account_id, $databaseId, File::get($compiled->directory . '/seed.sql'));
                $log[] = 'seeded D1 database ' . $databaseId;
            } catch (\Throwable $e) {
                return DeploymentResult::failed('D1 seed failed: ' . $e->getMessage());
            }
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $workerVersionId,
            url: $this->workerUrl($site, $environment),
            log: $log
        );
    }

    public function rollback(Site $site, string $workerVersionId): DeploymentResult
    {
        $connection = $this->connection($site);
        $client = CloudflareClient::forConnection($connection);
        $workerName = $this->workerName($site, 'production');

        try {
            $client->post(
                "/accounts/{$connection->account_id}/workers/scripts/{$workerName}/deployments",
                [
                    'strategy' => 'percentage',
                    'versions' => [
                        ['version_id' => $workerVersionId, 'percentage' => 100],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            return DeploymentResult::failed($e->getMessage());
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $workerVersionId,
            url: $this->workerUrl($site, 'production')
        );
    }

    public function syncMedia(Site $site): ?array
    {
        if (!$site->requiresR2() || empty($site->r2_bucket_name)) {
            return null;
        }

        $connection = $this->connection($site);
        $syncer = new MediaSyncService(
            CloudflareClient::forConnection($connection),
            $connection->account_id
        );

        return $syncer->sync($site, MediaSyncService::defaultSourceDir());
    }

    public function verify(string $url): bool
    {
        try {
            return Http::timeout(15)->withoutRedirecting()->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function prepareModule(CompiledSite $compiled, string $environment): string
    {
        $template = rtrim(config('flareweber.worker.template_path'), '/');
        $buildDir = dirname($compiled->directory) . '/deploy-' . $environment;

        $prebuilt = $template . '/dist/worker.mjs';
        if (is_file($prebuilt)) {
            File::ensureDirectoryExists($buildDir);
            File::copy($prebuilt, $buildDir . '/worker.mjs');

            return $buildDir . '/worker.mjs';
        }

        $bundled = (new WorkerBundler($template))->bundle($buildDir);

        if ($bundled === null) {
            throw new \RuntimeException(
                'Unable to bundle the Worker. Run "npm run bundle" in the worker template to produce dist/worker.mjs.'
            );
        }

        return $bundled;
    }

    /**
     * Build the Workers upload metadata (bindings + compatibility) for a site.
     *
     * @return array<string, mixed>
     */
    private function uploadMetadata(Site $site, bool $hasAssets): array
    {
        $bindings = [
            ['type' => 'plain_text', 'name' => 'SITE_ID', 'text' => (string) $site->id],
            ['type' => 'plain_text', 'name' => 'SITE_DOMAIN', 'text' => (string) ($site->domain ?? '')],
        ];

        if ($hasAssets) {
            $bindings[] = ['type' => 'assets', 'name' => 'ASSETS'];
        }

        $d1Id = $site->settings['d1_database_id'] ?? null;
        if ($d1Id) {
            $bindings[] = ['type' => 'd1', 'name' => 'DB', 'id' => $d1Id];
        }

        if ($site->r2_bucket_name) {
            $bindings[] = ['type' => 'r2_bucket', 'name' => 'MEDIA', 'bucket_name' => $site->r2_bucket_name];
        }

        return [
            'main_module' => 'worker.mjs',
            'compatibility_date' => config('flareweber.cloudflare.worker_compatibility_date', '2025-09-01'),
            'bindings' => $bindings,
        ];
    }

    private function workerName(Site $site, string $environment): string
    {
        if ($environment === 'preview' && $site->preview_worker_name) {
            return $site->preview_worker_name;
        }

        return $site->worker_name ?: 'flareweber-' . $site->id;
    }

    private function workerUrl(Site $site, string $environment): ?string
    {
        return 'https://' . $this->workerName($site, $environment) . '.workers.dev';
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
