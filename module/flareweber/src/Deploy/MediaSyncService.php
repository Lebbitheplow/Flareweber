<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Models\Site;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Syncs the Microweber media library (userfiles/media) into a site's R2
 * bucket so it can be served from /media/* at the edge (contract A).
 *
 * Object keys are the path relative to userfiles, so they always start
 * with "media/". Syncing is incremental: a sha256 manifest of uploaded keys
 * is stored on the site so a re-publish only uploads new/changed files and
 * deletes objects removed from the library.
 */
class MediaSyncService
{
    public const KEY_PREFIX = 'media/';

    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg', 'm4a',
        'pdf', 'txt', 'csv', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'zip',
    ];

    public function __construct(
        private readonly CloudflareClient $client,
        private readonly string $accountId
    ) {
    }

    /**
     * @return array{uploaded: int, deleted: int, unchanged: int, skipped: int, bytes: int}
     */
    public function sync(Site $site, ?string $sourceDir = null): array
    {
        $bucket = $site->r2_bucket_name;

        if ($bucket === null || $bucket === '') {
            throw new RuntimeException('Site has no R2 bucket to sync media into.');
        }

        $sourceDir ??= self::defaultSourceDir();
        $maxBytes = (int) config('flareweber.media.max_file_bytes', 104857600);

        $skipped = 0;
        $desired = $this->scan($sourceDir, $maxBytes, $skipped);
        $previous = $this->previousManifest($site);

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

        $this->storeManifest($site, array_map(
            fn (array $m) => ['hash' => $m['hash'], 'size' => $m['size']],
            $desired
        ));

        return [
            'uploaded' => $uploaded,
            'deleted' => $deleted,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'bytes' => $bytes,
        ];
    }

    /**
     * Walk the media directory and describe every syncable file. Hashes are
     * streamed from disk; file contents are only read when uploading.
     *
     * @return array<string, array{path: string, hash: string, size: int, content_type: string}>
     *         keyed by R2 object key ("media/{relative}")
     */
    public function scan(string $sourceDir, int $maxBytes, int &$skipped): array
    {
        $skipped = 0;
        $files = [];

        if (!is_dir($sourceDir)) {
            return $files;
        }

        $root = rtrim($sourceDir, '/\\') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));

            if (!self::isSyncable($relative)) {
                continue;
            }

            $size = $file->getSize();
            if ($size > $maxBytes) {
                $skipped++;

                continue;
            }

            $files[self::KEY_PREFIX . $relative] = [
                'path' => $file->getPathname(),
                'hash' => (string) hash_file('sha256', $file->getPathname()),
                'size' => $size,
                'content_type' => $this->mime($file->getPathname()),
            ];
        }

        ksort($files);

        return $files;
    }

    /**
     * Allowlisted extension, no dotfiles or dot-directories anywhere in the
     * path, and never server-side or markup files.
     */
    public static function isSyncable(string $relativePath): bool
    {
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (in_array($ext, ['php', 'phtml', 'htaccess', 'html', 'htm'], true)) {
            return false;
        }

        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }

    public static function defaultSourceDir(): string
    {
        $configured = config('flareweber.media.source');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(base_path(), '/') . '/userfiles/media';
    }

    /**
     * @return array<string, array{hash: string, size: int}>
     */
    private function previousManifest(Site $site): array
    {
        if (method_exists($site, 'mediaManifest')) {
            return $site->mediaManifest();
        }

        return [];
    }

    /**
     * @param array<string, array{hash: string, size: int}> $manifest
     */
    private function storeManifest(Site $site, array $manifest): void
    {
        if (method_exists($site, 'putMediaManifest')) {
            $site->putMediaManifest($manifest);
        }
    }

    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function mime(string $path): string
    {
        static $map = [
            'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'bmp' => 'image/bmp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
            'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'eot' => 'application/vnd.ms-fontobject', 'zip' => 'application/zip',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $detected = is_readable($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        return $detected ?: 'application/octet-stream';
    }
}
