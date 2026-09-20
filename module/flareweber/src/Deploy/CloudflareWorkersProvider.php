<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Cloudflare\ResourceProvisioner;
use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
        $buildDir = $this->prepareBuild($site, $compiled, $environment);

        $command = sprintf(
            'cd %s && npx --yes wrangler versions upload --message %s --quota-expiration-backup --json',
            escapeshellarg($buildDir),
            escapeshellarg('FlareWeber ' . $compiled->hash())
        );

        // TODO: replace the wrangler CLI call with the direct Workers "upload version"
        // REST API so OAuth access tokens work without wrangler on the host.
        $process = Process::timeout(600)->env([
            'CF_API_TOKEN' => $connection->access_token,
            'CF_ACCOUNT_ID' => $connection->account_id,
        ])->run($command);

        if (!$process->successful()) {
            return DeploymentResult::failed(
                $process->getErrorOutput() ?: $process->output()
            );
        }

        $result = json_decode(trim($process->output()), true) ?: [];

        $log = ['uploaded worker version ' . ($result['version_id'] ?? '?')];

        $databaseId = $site->settings['d1_database_id'] ?? null;
        if ($databaseId && File::exists($compiled->directory . '/seed.sql')) {
            try {
                $seeder = new D1Seeder(CloudflareClient::forConnection($connection));
                $seeder->applySql($connection->account_id, $databaseId, File::get($compiled->directory . '/schema.sql'));
                $seeder->applySql($connection->account_id, $databaseId, File::get($compiled->directory . '/seed.sql'));
                $log[] = 'seeded D1 database ' . $databaseId;
            } catch (\Throwable $e) {
                return DeploymentResult::failed('D1 seed failed: ' . $e->getMessage());
            }
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $result['version_id'] ?? null,
            url: $this->workerUrl($site, $environment),
            log: $log
        );
    }

    public function rollback(Site $site, string $workerVersionId): DeploymentResult
    {
        $connection = $this->connection($site);

        $process = Process::timeout(300)->env([
            'CF_API_TOKEN' => $connection->access_token,
            'CF_ACCOUNT_ID' => $connection->account_id,
        ])->run(sprintf(
            'npx --yes wrangler versions rollback %s --message %s --json',
            escapeshellarg($workerVersionId),
            escapeshellarg('FlareWeber rollback')
        ));

        if (!$process->successful()) {
            return DeploymentResult::failed($process->getErrorOutput() ?: $process->output());
        }

        return new DeploymentResult(
            success: true,
            workerVersionId: $workerVersionId,
            url: $this->workerUrl($site, 'production')
        );
    }

    public function verify(string $url): bool
    {
        try {
            return Http::timeout(15)->withoutRedirecting()->get($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function prepareBuild(Site $site, CompiledSite $compiled, string $environment): string
    {
        $template = rtrim(config('flareweber.worker.template_path'), '/');
        $buildDir = dirname($compiled->directory) . '/deploy-' . $environment;

        File::copyDirectory($template . '/src', $buildDir . '/src');
        File::copy($template . '/package.json', $buildDir . '/package.json');
        File::copy($template . '/tsconfig.json', $buildDir . '/tsconfig.json');
        File::copyDirectory($compiled->directory . '/worker/assets', $buildDir . '/assets');
        File::put($buildDir . '/schema.sql', File::get($template . '/schema.sql'));
        File::put($buildDir . '/wrangler.jsonc', $this->wranglerConfig($site, $environment));

        return $buildDir;
    }

    private function wranglerConfig(Site $site, string $environment): string
    {
        $isPreview = $environment === 'preview';
        $workerName = $isPreview && $site->preview_worker_name
            ? $site->preview_worker_name
            : ($site->worker_name ?: 'flareweber-' . $site->id);

        $config = [
            'name' => $workerName,
            'main' => 'src/index.ts',
            'compatibility_date' => '2025-09-01',
            'assets' => [
                'directory' => './assets',
                'binding' => 'ASSETS',
            ],
            'vars' => [
                'SITE_ID' => (string) $site->id,
                'SITE_DOMAIN' => $site->domain ?? '',
            ],
        ];

        if ($site->d1_database_name) {
            $config['d1_databases'] = [[
                'binding' => 'DB',
                'database_name' => $site->d1_database_name,
                'database_id' => $site->settings['d1_database_id'] ?? $site->d1_database_name,
            ]];
        }

        if ($site->r2_bucket_name) {
            $config['r2_buckets'] = [[
                'binding' => 'MEDIA',
                'bucket_name' => $site->r2_bucket_name,
            ]];
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function workerUrl(Site $site, string $environment): ?string
    {
        $name = $environment === 'preview'
            ? ($site->preview_worker_name ?: $site->worker_name)
            : $site->worker_name;

        return $name ? "https://{$name}.workers.dev" : null;
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
