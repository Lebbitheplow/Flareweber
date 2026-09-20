<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Deploys a Worker (module + static assets) using the Cloudflare Workers REST
 * API directly, so an OAuth access token is enough and no wrangler CLI is
 * required on the host.
 *
 * Flow (see developers.cloudflare.com/workers/static-assets/direct-upload):
 *   1. POST .../scripts/{name}/assets-upload-session  -> {jwt, buckets}
 *   2. POST .../assets/upload?base64=true             -> completion jwt
 *   3. PUT  .../scripts/{name}                        -> create + deploy version
 *   4. POST .../scripts/{name}/subdomain {enabled}    -> serve on workers.dev
 */
class WorkerUploader
{
    private const MODULE_CONTENT_TYPE = 'application/javascript+module';

    public function __construct(
        private readonly CloudflareClient $client,
        private readonly string $accountId
    ) {
    }

    /**
     * Upload the worker module + assets and deploy immediately.
     *
     * @param array<string, mixed> $metadata  base metadata (main_module, bindings, compatibility_date, ...)
     * @return string the deployed version id
     */
    public function deploy(string $workerName, string $modulePath, ?string $assetsDir, array $metadata): string
    {
        if (!is_file($modulePath)) {
            throw new RuntimeException("Worker module not found for upload: {$modulePath}");
        }

        $completionJwt = $this->uploadAssets($workerName, $assetsDir);

        if ($completionJwt !== null) {
            $metadata['assets'] = array_merge($metadata['assets'] ?? [], ['jwt' => $completionJwt]);
        } else {
            unset($metadata['assets']);
        }

        $moduleName = basename($modulePath);
        $metadata['main_module'] = $moduleName;

        $response = $this->client->multipart(
            'put',
            "/accounts/{$this->accountId}/workers/scripts/{$workerName}",
            [
                [
                    'name' => 'metadata',
                    'contents' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'headers' => ['Content-Type' => 'application/json'],
                ],
                [
                    'name' => $moduleName,
                    'contents' => (string) file_get_contents($modulePath),
                    'filename' => $moduleName,
                    'headers' => ['Content-Type' => self::MODULE_CONTENT_TYPE],
                ],
            ]
        );

        $versionId = $response['result'] ?? null;

        if (!is_string($versionId)) {
            throw new RuntimeException('Worker upload did not return a version id: ' . json_encode($response));
        }

        return $versionId;
    }

    /**
     * Upload the static assets for a worker and return the completion JWT,
     * or null when there are no assets to attach.
     */
    public function uploadAssets(string $workerName, ?string $assetsDir): ?string
    {
        if ($assetsDir === null || !is_dir($assetsDir)) {
            return null;
        }

        $manifest = $this->buildManifest($assetsDir);

        if ($manifest === []) {
            return null;
        }

        $session = $this->client->post(
            "/accounts/{$this->accountId}/workers/scripts/{$workerName}/assets-upload-session",
            ['manifest' => $manifest]
        );

        $result = $session['result'] ?? [];
        $uploadJwt = $result['jwt'] ?? null;
        $buckets = $result['buckets'] ?? [];

        if (!is_string($uploadJwt)) {
            throw new RuntimeException('Asset upload session failed: ' . json_encode($session));
        }

        if ($buckets === []) {
            return $uploadJwt;
        }

        // hash => absolute path
        $byHash = [];
        foreach ($manifest as $path => $entry) {
            $byHash[$entry['hash']] = $assetsDir . $path;
        }

        $completionJwt = $uploadJwt;

        foreach ($buckets as $bucket) {
            $parts = [];

            foreach ($bucket as $hash) {
                if (!isset($byHash[$hash]) || !is_file($byHash[$hash])) {
                    throw new RuntimeException("Upload bucket referenced unknown asset hash: {$hash}");
                }

                $parts[] = [
                    'name' => $hash,
                    'contents' => base64_encode((string) file_get_contents($byHash[$hash])),
                    'headers' => ['Content-Type' => $this->mime($byHash[$hash])],
                ];
            }

            $response = $this->client->multipart(
                'post',
                "/accounts/{$this->accountId}/workers/assets/upload",
                $parts,
                ['base64' => true],
                $uploadJwt
            );

            if (!empty($response['result']['jwt'])) {
                $completionJwt = $response['result']['jwt'];
            }
        }

        return $completionJwt;
    }

    public function enableSubdomain(string $workerName): void
    {
        $this->client->post(
            "/accounts/{$this->accountId}/workers/scripts/{$workerName}/subdomain",
            ['enabled' => true]
        );
    }

    public function hasAssets(?string $assetsDir): bool
    {
        if ($assetsDir === null || !is_dir($assetsDir)) {
            return false;
        }

        $root = rtrim($assetsDir, '/\\') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the asset manifest: { "/path": {hash, size} }.
     *
     * The hash is sha256(base64(content) + extension), first 32 hex chars,
     * matching the Cloudflare direct-upload contract. It is used only as a
     * content-addressable key, so it must simply be deterministic per content.
     *
     * @return array<string, array{hash: string, size: int}>
     */
    private function buildManifest(string $assetsDir): array
    {
        $manifest = [];

        $root = rtrim($assetsDir, '/\\') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $relative = '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $hash = substr(hash('sha256', base64_encode($contents) . $extension), 0, 32);

            $manifest[$relative] = [
                'hash' => $hash,
                'size' => strlen($contents),
            ];
        }

        return $manifest;
    }

    private function mime(string $path): string
    {
        static $map = [
            'html' => 'text/html',
            'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'txt' => 'text/plain',
            'xml' => 'application/xml',
            'robots' => 'text/plain',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $detected = is_readable($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        return $detected ?: 'application/octet-stream';
    }
}
