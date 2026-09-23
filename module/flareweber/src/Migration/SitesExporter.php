<?php

namespace FlareWeber\Migration;

use FlareWeber\Models\Site;

/**
 * Produces a portable, secret-free bundle of FlareWeber sites: site rows,
 * deployment history, Microweber content (pages, posts, products with
 * content_data, custom fields, categories) and, when written as a .zip,
 * the media library. Cloudflare tokens, Stripe keys, media manifests and
 * per-account resource ids (D1, R2, worker names) are never included: they
 * belong to the old account and are recreated on the next publish.
 */
class SitesExporter
{
    public const FORMAT = 'flareweber-site-bundle';

    public const VERSION = 2;

    private const STRIPPED_SETTINGS = [
        'r2_media_manifest', 'd1_database_id', 'zone_id', 'workers_dev_url', 'stripe_account_id', 'stripe_method',
    ];

    public function __construct(
        private readonly ContentExporter $content = new ContentExporter(),
        private readonly BundleArchive $archive = new BundleArchive()
    ) {
    }

    /**
     * @param iterable<Site> $sites
     * @return array<string, mixed>
     */
    public function bundle(iterable $sites, bool $includeContent = true): array
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

        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->format(\DateTimeInterface::ATOM),
            'connections' => array_values($connections),
            'sites' => $exported,
        ];

        if ($includeContent) {
            $bundle['content'] = $this->content->export();
        }

        return $bundle;
    }

    /**
     * Write the bundle as a .zip with site.json and media/ (userfiles/media).
     *
     * @param array<string, mixed> $bundle
     * @return int media files added
     */
    public function writeZip(string $path, array $bundle, bool $includeMedia = true): int
    {
        $mediaDir = $includeMedia ? rtrim(base_path(), '/') . '/userfiles/media' : null;

        return $this->archive->write($path, $bundle, $mediaDir);
    }

    /**
     * @return array<string, mixed>
     */
    public function site(Site $site): array
    {
        return [
            'name' => $site->name,
            'domain' => $site->domain,
            'cloudflare_account_id' => $site->cloudflareConnection?->account_id,
            'settings' => $this->safeSettings($site->settings ?? []),
            'published_at' => $site->published_at?->format(\DateTimeInterface::ATOM),
            'deployments' => $site->deployments()->orderBy('id')->get()
                ->map(fn ($d) => [
                    'version' => $d->version,
                    'environment' => $d->environment,
                    'status' => $d->status,
                    'artifact_hash' => $d->artifact_hash,
                    'worker_version_id' => $d->worker_version_id,
                    'url' => $d->url,
                    'created_at' => $d->created_at?->format(\DateTimeInterface::ATOM),
                    'finished_at' => $d->finished_at?->format(\DateTimeInterface::ATOM),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Strip secrets and account-bound resource identifiers before the
     * settings leave the install.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function safeSettings(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, self::STRIPPED_SETTINGS, true) || preg_match('/secret|token|password|private|manifest/i', $key)) {
                unset($settings[$key]);
            }
        }

        return $settings;
    }
}
