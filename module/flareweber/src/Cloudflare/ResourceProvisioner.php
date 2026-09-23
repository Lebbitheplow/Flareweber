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

    /**
     * @return array{worker: array, d1: array|null, r2: array|null}
     */
    public function provision(Site $site, string $accountId, bool $withD1, bool $withR2): array
    {
        // The worker name is the base for the D1 and R2 names.
        $worker = $this->ensureWorker($site, $accountId);

        return [
            'worker' => $worker,
            'd1' => $withD1 ? $this->ensureD1($site, $accountId) : null,
            'r2' => $withR2 ? $this->ensureR2($site, $accountId) : null,
        ];
    }

    public function ensureD1(Site $site, string $accountId): array
    {
        $name = $site->d1_database_name ?: $this->d1Name($site);

        $existing = $this->findD1($accountId, $name);
        if ($existing !== null) {
            $site->forceFill(['d1_database_name' => $name])->save();
            $this->storeD1Id($site, $existing['uuid'] ?? null);

            return $existing;
        }

        $created = $this->client->post("/accounts/{$accountId}/d1/database", ['name' => $name]);
        $result = $created['result'] ?? [];

        $site->forceFill(['d1_database_name' => $name])->save();
        $this->storeD1Id($site, $result['uuid'] ?? null);

        return $result + ['created' => true];
    }

    public function ensureR2(Site $site, string $accountId): array
    {
        $bucket = $site->r2_bucket_name ?: $this->r2Name($site);

        $existing = $this->client->get("/accounts/{$accountId}/r2/buckets/{$bucket}", throwOnError: false);
        if (($existing['success'] ?? false) && !empty($existing['result'])) {
            $site->forceFill(['r2_bucket_name' => $bucket])->save();

            return (array) $existing['result'];
        }

        $created = $this->client->post("/accounts/{$accountId}/r2/buckets", ['name' => $bucket]);
        $site->forceFill(['r2_bucket_name' => $bucket])->save();

        return (array) ($created['result'] ?? ['name' => $bucket]) + ['created' => true];
    }

    /**
     * Pick (and persist) a worker script name unique on Cloudflare and in the
     * local sites table. GET .../scripts/{name} returns the script body (not
     * JSON), so existence is checked by status only.
     */
    public function ensureWorker(Site $site, string $accountId): array
    {
        if ($site->worker_name) {
            return [
                'script_name' => $site->worker_name,
                'exists' => $this->scriptExists($accountId, $site->worker_name),
            ];
        }

        $base = $this->workerName($site);
        $candidate = $base;
        $suffix = 2;

        while ($this->nameTaken($site, $accountId, $candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 50) {
                throw new \RuntimeException("Could not find a free worker name for '{$base}'.");
            }
        }

        $site->forceFill(['worker_name' => $candidate])->save();

        return ['script_name' => $candidate, 'exists' => false, 'created' => true];
    }

    public function deleteSiteResources(Site $site, string $accountId): void
    {
        if ($site->worker_name) {
            $this->client->delete("/accounts/{$accountId}/workers/scripts/{$site->worker_name}", throwOnError: false);
        }
        if ($site->preview_worker_name) {
            $this->client->delete("/accounts/{$accountId}/workers/scripts/{$site->preview_worker_name}", throwOnError: false);
        }
        if ($site->d1_database_name && ($db = $this->findD1($accountId, $site->d1_database_name))) {
            $this->client->delete("/accounts/{$accountId}/d1/database/{$db['uuid']}", throwOnError: false);
        }
        if ($site->r2_bucket_name) {
            $this->client->delete("/accounts/{$accountId}/r2/buckets/{$site->r2_bucket_name}", throwOnError: false);
        }
    }

    public function scriptExists(string $accountId, string $name): bool
    {
        return $this->client->exists("/accounts/{$accountId}/workers/scripts/{$name}");
    }

    private function nameTaken(Site $site, string $accountId, string $candidate): bool
    {
        $local = Site::withTrashed()
            ->where('id', '!=', $site->id)
            ->where(function ($q) use ($candidate) {
                $q->where('worker_name', $candidate)
                    ->orWhere('preview_worker_name', $candidate . '--preview');
            })
            ->exists();

        if ($local) {
            return true;
        }

        return $this->scriptExists($accountId, $candidate)
            || $this->scriptExists($accountId, $candidate . '--preview');
    }

    /** D1 list supports a ?name= filter plus page/per_page pagination. */
    private function findD1(string $accountId, string $name): ?array
    {
        $page = 1;

        do {
            $payload = $this->client->get("/accounts/{$accountId}/d1/database", [
                'name' => $name,
                'page' => $page,
                'per_page' => 100,
            ]);

            foreach ($payload['result'] ?? [] as $database) {
                if (($database['name'] ?? null) === $name) {
                    return $database;
                }
            }

            $info = $payload['result_info'] ?? [];
            $more = isset($info['total_pages']) ? $page < (int) $info['total_pages'] : count($payload['result'] ?? []) >= 100;
            $page++;
        } while ($more && $page <= 50);

        return null;
    }

    private function storeD1Id(Site $site, ?string $uuid): void
    {
        if ($uuid === null || $uuid === '') {
            return;
        }

        $settings = $site->settings ?? [];
        $settings['d1_database_id'] = $uuid;
        $site->forceFill(['settings' => $settings])->save();
    }

    private function workerName(Site $site): string
    {
        $slug = substr(Str::slug($site->name), 0, 40);
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'site-' . $site->id;
        }

        return 'flareweber-' . $slug;
    }

    private function d1Name(Site $site): string
    {
        return ($site->worker_name ?: $this->workerName($site)) . '-db';
    }

    private function r2Name(Site $site): string
    {
        return ($site->worker_name ?: $this->workerName($site)) . '-media';
    }
}
