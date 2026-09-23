<?php

namespace FlareWeber\Migration;

use FlareWeber\Models\CloudflareConnection;
use FlareWeber\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Imports a bundle produced by SitesExporter. Sites are created without any
 * tokens, secrets or account-bound resource ids; Cloudflare connections are
 * recreated as "disconnected" so the user re-links them before publishing.
 * Microweber content is recreated through the content models.
 */
class SitesImporter
{
    private const ALLOWED_SETTINGS = [
        'ecommerce', 'forms', 'media', 'template', 'contact_email', 'sync_inventory', 'skip_validation',
    ];

    public function __construct(private readonly ContentImporter $content = new ContentImporter())
    {
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array{sites: int, skipped: int, content: int, categories: int}
     */
    public function import(array $bundle, bool $includeContent = true): array
    {
        $this->validate($bundle);

        $imported = 0;
        $skipped = 0;
        $content = ['content' => 0, 'categories' => 0, 'skipped' => 0];

        try {
            DB::transaction(function () use ($bundle, $includeContent, &$imported, &$skipped, &$content) {
                foreach ($bundle['sites'] as $data) {
                    if ($this->siteExists($data)) {
                        $skipped++;

                        continue;
                    }

                    $this->createSite($data);
                    $imported++;
                }

                if ($includeContent && is_array($bundle['content'] ?? null)) {
                    $content = $this->content->import($bundle['content']);
                }
            });
        } catch (QueryException $e) {
            throw new \RuntimeException('Import failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }

        return [
            'sites' => $imported,
            'skipped' => $skipped,
            'content' => $content['content'],
            'categories' => $content['categories'],
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function validate(array $bundle): void
    {
        if (($bundle['format'] ?? null) !== SitesExporter::FORMAT) {
            throw new \InvalidArgumentException('Not a FlareWeber site bundle.');
        }

        $version = $bundle['version'] ?? 0;
        if (!is_int($version) && !ctype_digit((string) $version)) {
            throw new \InvalidArgumentException('Bundle version is not a number.');
        }
        if ((int) $version > SitesExporter::VERSION) {
            throw new \InvalidArgumentException(
                'Bundle version ' . $version . ' is newer than this FlareWeber install supports (' . SitesExporter::VERSION . ').'
            );
        }

        if (!is_array($bundle['sites'] ?? null)) {
            throw new \InvalidArgumentException('Bundle has no "sites" list.');
        }

        foreach ($bundle['sites'] as $i => $site) {
            if (!is_array($site) || !is_string($site['name'] ?? null) || trim($site['name']) === '') {
                throw new \InvalidArgumentException("Site #{$i} is missing a name.");
            }
            if (isset($site['domain']) && !is_string($site['domain']) && $site['domain'] !== null) {
                throw new \InvalidArgumentException("Site #{$i} has an invalid domain.");
            }
            if (isset($site['settings']) && !is_array($site['settings'])) {
                throw new \InvalidArgumentException("Site #{$i} has invalid settings.");
            }
            if (isset($site['deployments']) && !is_array($site['deployments'])) {
                throw new \InvalidArgumentException("Site #{$i} has an invalid deployments list.");
            }
        }

        if (isset($bundle['content']) && !is_array($bundle['content'])) {
            throw new \InvalidArgumentException('Bundle "content" must be an object.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSite(array $data): Site
    {
        $site = new Site();
        $site->forceFill([
            'name' => $this->uniqueName(trim((string) $data['name'])),
            'domain' => isset($data['domain']) && $data['domain'] !== '' ? (string) $data['domain'] : null,
            'settings' => $this->settings((array) ($data['settings'] ?? [])),
            'published_at' => $this->timestamp($data['published_at'] ?? null),
            'cloudflare_connection_id' => $this->connectionId($data['cloudflare_account_id'] ?? null),
        ])->save();

        foreach ($data['deployments'] ?? [] as $deployment) {
            if (!is_array($deployment)) {
                continue;
            }

            $site->deployments()->create([
                'version' => max(1, (int) ($deployment['version'] ?? 0)),
                'environment' => in_array($deployment['environment'] ?? null, ['production', 'preview'], true)
                    ? $deployment['environment']
                    : 'production',
                'status' => in_array($deployment['status'] ?? null, ['success', 'failed', 'running'], true)
                    ? $deployment['status']
                    : 'success',
                'artifact_hash' => isset($deployment['artifact_hash']) ? (string) $deployment['artifact_hash'] : null,
                'worker_version_id' => isset($deployment['worker_version_id']) ? (string) $deployment['worker_version_id'] : null,
                'url' => isset($deployment['url']) ? (string) $deployment['url'] : null,
                'finished_at' => $this->timestamp($deployment['finished_at'] ?? null),
            ]);
        }

        return $site;
    }

    /**
     * Only the user-facing settings survive an import; resource ids and
     * anything secret-like are dropped even if present in the file.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function settings(array $settings): array
    {
        $out = [];
        foreach (self::ALLOWED_SETTINGS as $key) {
            if (array_key_exists($key, $settings) && is_scalar($settings[$key])) {
                $out[$key] = $settings[$key];
            }
        }

        return $out;
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return strtotime($value) !== false ? date('Y-m-d H:i:s', (int) strtotime($value)) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function siteExists(array $data): bool
    {
        $name = trim((string) ($data['name'] ?? ''));

        return $name !== '' && Site::query()->where('name', $name)->exists();
    }

    /**
     * Soft-deleted sites keep their name; suffix the new one so a unique
     * index (if any) and the UI stay unambiguous.
     */
    private function uniqueName(string $name): string
    {
        $candidate = $name;
        for ($i = 2; Site::withTrashed()->where('name', $candidate)->exists(); $i++) {
            $candidate = $name . ' (' . $i . ')';
        }

        return $candidate;
    }

    private function connectionId(mixed $accountId): ?int
    {
        if (!is_string($accountId) || $accountId === '') {
            return null;
        }

        $connection = CloudflareConnection::query()->where('account_id', $accountId)->first();
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
