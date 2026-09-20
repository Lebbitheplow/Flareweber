<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Models\Site;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Syncs the local media library (public/userfiles) into a site's R2 bucket so
 * large media lives outside the Worker static-asset bundle and can be served
 * from /media/* at the edge.
 *
 * Syncing is incremental: a sha256 manifest of every uploaded key is stored on
 * the site so a re-publish only uploads new/changed objects and deletes objects
 * that were removed from the library since the last publish.
 */
class MediaSyncService
{
    public function __construct(
        private readonly CloudflareClient $client,
        private readonly string $accountId
    ) {
    }

    /**
     * @return array{uploaded: int, deleted: int, unchanged: int, skipped: int, bytes: int}
     */
    public function sync(Site $site, string $sourceDir): array
    {
        $bucket = $site->r2_bucket_name;

        if ($bucket === null || $bucket === '') {
            throw new RuntimeException('Site has no R2 bucket to sync media into.');
        }

        $prefix = trim((string) config('flareweber.media.prefix', 'media'), '/');
        $maxBytes = (int) config('flareweber.media.max_file_bytes', 104857600);
        $manifestKey = 'r2_media_manifest';

        $skipped = 0;
        $desired = $this->scan($sourceDir, $prefix, $maxBytes, $skipped);
        $previous = (array) ($site->settings[$manifestKey] ?? []);

        $uploaded = 0;
        $unchanged = 0;
        $bytes = 0;

        foreach ($desired as $key => $meta) {
            if (($previous[$key]['hash'] ?? null) === $meta['hash']) {
                $unchanged++;

                continue;
            }

            $this->client->putRaw(
                "/accounts/{$this->accountId}/r2/buckets/{$bucket}/objects/{$this->encodeKey($key)}",
                (string) file_get_contents($meta['path']),
                $meta['content_type']
            );

            $uploaded++;
            $bytes += $meta['size'];
        }

        $deleted = 0;

        foreach (array_keys($previous) as $key) {
            if (!isset($desired[$key])) {
                $this->client->delete(
                    "/accounts/{$this->accountId}/r2/buckets/{$bucket}/objects/{$this->encodeKey($key)}",
                    throwOnError: false
                );
                $deleted++;
            }
        }

        $settings = $site->settings ?? [];
        $settings[$manifestKey] = array_map(
            fn (array $m) => ['hash' => $m['hash'], 'size' => $m['size']],
            $desired
        );
        $site->forceFill(['settings' => $settings])->save();

        return [
            'uploaded' => $uploaded,
            'deleted' => $deleted,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array<string, array{path: string, hash: string, size: int, content_type: string}>
     *         keyed by full R2 object key ("{prefix}/{relative}")
     */
    private function scan(string $sourceDir, string $prefix, int $maxBytes, int &$skipped): array
    {
        $skipped = 0;
        $files = [];

        if (!is_dir($sourceDir)) {
            return $files;
        }

        $root = rtrim($sourceDir, '/\\') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $size = $file->getSize();

            if ($size > $maxBytes) {
                $skipped++;

                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen($root)));
            $key = ($prefix !== '' ? $prefix . '/' : '') . $relative;
            $contents = (string) file_get_contents($path);

            $files[$key] = [
                'path' => $path,
                'hash' => hash('sha256', $contents),
                'size' => $size,
                'content_type' => $this->mime($path),
            ];
        }

        return $files;
    }

    public static function defaultSourceDir(): string
    {
        $configured = config('flareweber.media.source');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(public_path(), '/') . '/userfiles';
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function mime(string $path): string
    {
        static $map = [
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'pdf' => 'application/pdf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'txt' => 'text/plain',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $detected = is_readable($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        return $detected ?: 'application/octet-stream';
    }
}
