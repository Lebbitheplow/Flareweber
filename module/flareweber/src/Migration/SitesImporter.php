<?php

namespace FlareWeber\Migration;

use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Imports a bundle produced by SitesExporter. Creates sites (and records of
 * their Cloudflare connections) without any tokens; connections are marked
 * disconnected so the user re-links them via OAuth before publishing.
 */
class SitesImporter
{
    /**
     * @return array{sites:int, skipped:int}
     */
    public function import(array $bundle): array
    {
        if (($bundle['format'] ?? null) !== SitesExporter::FORMAT) {
            throw new \InvalidArgumentException('Not a FlareWeber site bundle.');
        }

        if ((int) ($bundle['version'] ?? 0) > SitesExporter::VERSION) {
            throw new \InvalidArgumentException(
                'Bundle version ' . $bundle['version'] . ' is newer than this FlareWeber install supports ('
                . SitesExporter::VERSION . ').'
            );
        }

        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($bundle, &$imported, &$skipped) {
            foreach ($bundle['sites'] ?? [] as $data) {
                if ($this->siteExists($data)) {
                    $skipped++;

                    continue;
                }

                $site = new Site();
                $site->forceFill([
                    'name' => $data['name'] ?? 'Imported site',
                    'domain' => $data['domain'] ?? null,
                    'worker_name' => $data['worker_name'] ?? null,
                    'preview_worker_name' => $data['preview_worker_name'] ?? null,
                    'd1_database_name' => $data['d1_database_name'] ?? null,
                    'r2_bucket_name' => $data['r2_bucket_name'] ?? null,
                    'settings' => $data['settings'] ?? [],
                    'published_at' => isset($data['published_at']) ? $data['published_at'] : null,
                    'cloudflare_connection_id' => $this->connectionId($data['cloudflare_account_id'] ?? null),
                ])->save();

                foreach ($data['deployments'] ?? [] as $deployment) {
                    $site->deployments()->create([
                        'version' => (int) ($deployment['version'] ?? 0),
                        'environment' => $deployment['environment'] ?? 'production',
                        'status' => $deployment['status'] ?? 'success',
                        'artifact_hash' => $deployment['artifact_hash'] ?? null,
                        'worker_version_id' => $deployment['worker_version_id'] ?? null,
                        'url' => $deployment['url'] ?? null,
                        'finished_at' => null,
                    ]);
                }

                $imported++;
            }
        });

        return ['sites' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function siteExists(array $data): bool
    {
        if (!empty($data['worker_name'])) {
            return Site::where('worker_name', $data['worker_name'])->exists();
        }

        return Site::where('name', $data['name'] ?? '')->exists();
    }

    private function connectionId(?string $accountId): ?int
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        $connection = CloudflareConnection::where('account_id', $accountId)->first();

        if ($connection !== null) {
            return $connection->id;
        }

        return CloudflareConnection::create([
            'account_id' => $accountId,
            'account_name' => null,
            'access_token' => '',
            'status' => 'disconnected',
        ])->id;
    }
}
