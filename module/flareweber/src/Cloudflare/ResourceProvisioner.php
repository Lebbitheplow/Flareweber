<?php

namespace FlareWeber\Cloudflare;

use FlareWeber\Models\Site;
use Illuminate\Support\Str;

class ResourceProvisioner
{
    public function __construct(
        private readonly CloudflareClient $client
    ) {
    }

    public function provision(Site $site, string $accountId, bool $withD1, bool $withR2): array
    {
        $result = ['worker' => null, 'd1' => null, 'r2' => null];

        $result['d1'] = $withD1 ? $this->ensureD1($site, $accountId) : null;
        $result['r2'] = $withR2 ? $this->ensureR2($site, $accountId) : null;
        $result['worker'] = $this->ensureWorker($site, $accountId);

        return $result;
    }

    public function ensureD1(Site $site, string $accountId): array
    {
        $name = $site->d1_database_name ?: $this->d1Name($site);

        $existing = $this->findD1($accountId, $name);
        if ($existing !== null) {
            $site->forceFill(['d1_database_name' => $name])->save();

            return $existing;
        }

        $created = $this->client->post("/accounts/{$accountId}/d1/database", ['name' => $name]);
        $site->forceFill(['d1_database_name' => $name])->save();

        $result = $created['result'] ?? [];
        if (!empty($result['uuid'])) {
            $settings = $site->settings ?? [];
            $settings['d1_database_id'] = $result['uuid'];
            $site->forceFill(['settings' => $settings])->save();
        }

        return $result;
    }

    public function ensureR2(Site $site, string $accountId): array
    {
        $bucket = $site->r2_bucket_name ?: $this->r2Name($site);

        $existing = $this->client->get("/accounts/{$accountId}/r2/buckets/{$bucket}", throwOnError: false);
        if (($existing['success'] ?? false) && !empty($existing['result'])) {
            $site->forceFill(['r2_bucket_name' => $bucket])->save();

            return $existing['result'];
        }

        $this->client->put("/accounts/{$accountId}/r2/buckets/{$bucket}");
        $site->forceFill(['r2_bucket_name' => $bucket])->save();

        return ['bucket' => $bucket, 'created' => true];
    }

    public function ensureWorker(Site $site, string $accountId): array
    {
        $name = $site->worker_name ?: $this->workerName($site);

        $existing = $this->client->get("/accounts/{$accountId}/workers/scripts/{$name}", throwOnError: false);
        if (($existing['success'] ?? false) && !empty($existing['result'])) {
            $site->forceFill(['worker_name' => $name])->save();

            return $existing['result'];
        }

        $site->forceFill(['worker_name' => $name])->save();

        return ['script_name' => $name, 'created' => true];
    }

    public function deleteSiteResources(Site $site, string $accountId): void
    {
        if ($site->worker_name) {
            $this->client->delete("/accounts/{$accountId}/workers/scripts/{$site->worker_name}");
        }
        if ($site->d1_database_name && ($db = $this->findD1($accountId, $site->d1_database_name))) {
            $this->client->delete("/accounts/{$accountId}/d1/database/{$db['uuid']}");
        }
        if ($site->r2_bucket_name) {
            $this->client->delete("/accounts/{$accountId}/r2/buckets/{$site->r2_bucket_name}");
        }
    }

    private function findD1(string $accountId, string $name): ?array
    {
        $page = $this->client->get("/accounts/{$accountId}/d1/database");

        foreach ($page['result'] ?? [] as $database) {
            if (($database['name'] ?? null) === $name) {
                return $database;
            }
        }

        return null;
    }

    private function workerName(Site $site): string
    {
        return 'flareweber-' . Str::slug($site->name);
    }

    private function d1Name(Site $site): string
    {
        return $this->workerName($site) . '-db';
    }

    private function r2Name(Site $site): string
    {
        return $this->workerName($site) . '-media';
    }
}
