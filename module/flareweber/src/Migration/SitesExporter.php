<?php

namespace FlareWeber\Migration;

use FlareWeber\Models\Site;

/**
 * Produces a portable, secret-free JSON bundle of FlareWeber site configuration
 * and deployment history, so a site can be moved between FlareWeber installs
 * (e.g. desktop -> self-hosted server). Cloudflare OAuth tokens are never
 * included; the importer recreates connections as "disconnected" to be re-linked.
 */
class SitesExporter
{
    public const FORMAT = 'flareweber-site-bundle';

    public const VERSION = 1;

    /**
     * @param  iterable<Site>  $sites
     * @return array<string, mixed>
     */
    public function bundle(iterable $sites): array
    {
        $exported = [];
        $connections = [];

        foreach ($sites as $site) {
            $exported[] = $this->site($site);

            $connection = $site->cloudflareConnection;

            if ($connection !== null && $connection->account_id !== '') {
                $connections[$connection->account_id] = [
                    'account_id' => $connection->account_id,
                    'account_name' => $connection->account_name,
                ];
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->format(\DateTimeInterface::ATOM),
            'connections' => array_values($connections),
            'sites' => $exported,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function site(Site $site): array
    {
        return [
            'name' => $site->name,
            'domain' => $site->domain,
            'worker_name' => $site->worker_name,
            'preview_worker_name' => $site->preview_worker_name,
            'd1_database_name' => $site->d1_database_name,
            'r2_bucket_name' => $site->r2_bucket_name,
            'cloudflare_account_id' => $site->cloudflareConnection?->account_id,
            'settings' => $this->safeSettings($site->settings ?? []),
            'published_at' => $site->published_at?->format(\DateTimeInterface::ATOM),
            'deployments' => $site->deployments()->get()
                ->map(fn ($d) => [
                    'version' => $d->version,
                    'environment' => $d->environment,
                    'status' => $d->status,
                    'artifact_hash' => $d->artifact_hash,
                    'worker_version_id' => $d->worker_version_id,
                    'url' => $d->url,
                    'created_at' => $d->created_at?->format(\DateTimeInterface::ATOM),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Strip anything that looks like a secret before it leaves the install.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function safeSettings(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (is_string($key) && preg_match('/secret|token|password|private/i', $key)) {
                unset($settings[$key]);
            }
        }

        return $settings;
    }
}
