<?php

namespace FlareWeber\Admin;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use MicroweberPackages\Media\Models\Media;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Media library for the admin SPA: rows from Microweber's `media` table plus
 * loose files under userfiles/media. Uploads are written to
 * userfiles/media/default with a strict extension allowlist and a sanitised
 * file name (MediaManager::upload is bound to $_FILES and exits, so it is
 * not usable from a JSON controller).
 */
class MediaRepository
{
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp',
        'mp4', 'webm', 'mov', 'mp3', 'wav', 'ogg', 'm4a',
        'pdf', 'txt', 'csv', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'zip',
    ];

    private const FILE_ID_PREFIX = 'f_';

    private const MAX_SCAN = 2000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $items = [];
        $seen = [];

        foreach (Media::query()->orderByDesc('id')->get() as $row) {
            $filename = trim((string) $row->filename);
            if ($filename === '') {
                continue;
            }

            $url = $this->absoluteUrl($filename);
            $path = $this->localPath($url);
            if ($path !== null) {
                $seen[$path] = true;
            }

            $name = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
            $title = trim(strip_tags((string) ($row->title ?? '')));

            $items[] = [
                'id' => (int) $row->id,
                'filename' => $name,
                'url' => $url,
                'title' => $title !== '' ? $title : $name,
                'size' => $path !== null && is_file($path) ? filesize($path) : null,
                'updated_at' => $row->updated_at instanceof CarbonInterface
                    ? $row->updated_at->toIso8601String()
                    : null,
            ];
        }

        foreach ($this->scan() as $relative => $path) {
            if (isset($seen[$path])) {
                continue;
            }
            $items[] = $this->fileEntry($relative, $path);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function upload(UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Upload failed.');
        }

        $original = (string) $file->getClientOriginalName();
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('File type not allowed.');
        }

        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($original, PATHINFO_FILENAME)) ?? '';
        $base = trim(substr($base, 0, 80), '-_');
        if ($base === '') {
            $base = 'file-' . date('YmdHis');
        }

        $dir = $this->basePath() . 'default' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new InvalidArgumentException('Media directory is not writable.');
        }

        $name = $base . '.' . $extension;
        $counter = 1;
        while (file_exists($dir . $name)) {
            $name = $base . '-' . $counter++ . '.' . $extension;
        }

        $file->move($dir, $name);

        return $this->fileEntry('default/' . $name, $dir . $name);
    }

    /**
     * Numeric ids delete the `media` row through Microweber's media manager
     * (the file stays on disk, as in the Microweber admin). File ids remove
     * the file from userfiles/media after a realpath containment check.
     */
    public function delete(string $id): bool
    {
        if (ctype_digit($id)) {
            if (Media::query()->where('id', (int) $id)->doesntExist()) {
                return false;
            }
            app()->media_manager->delete(['id' => (int) $id]);

            return true;
        }

        $relative = $this->decodeFileId($id);
        if ($relative === null) {
            return false;
        }

        $base = realpath($this->basePath());
        $path = realpath($this->basePath() . $relative);

        if ($base === false || $path === false || !is_file($path)
            || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * @return array<string, string> relative path (forward slashes) => absolute path
     */
    private function scan(): array
    {
        $base = $this->basePath();
        if (!is_dir($base)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if (count($found) >= self::MAX_SCAN) {
                break;
            }
            if (!$entry->isFile() || str_starts_with($entry->getFilename(), '.')) {
                continue;
            }
            if (!in_array(strtolower($entry->getExtension()), self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($base))), '/');
            if (str_contains($relative, '/.')) {
                continue;
            }

            $found[$relative] = $entry->getPathname();
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function fileEntry(string $relative, string $path): array
    {
        $segments = array_map('rawurlencode', explode('/', $relative));
        $mtime = is_file($path) ? filemtime($path) : false;

        return [
            'id' => self::FILE_ID_PREFIX . rtrim(strtr(base64_encode($relative), '+/', '-_'), '='),
            'filename' => basename($relative),
            'url' => $this->baseUrl() . implode('/', $segments),
            'title' => basename($relative),
            'size' => is_file($path) ? filesize($path) : null,
            'updated_at' => $mtime ? Carbon::createFromTimestamp($mtime)->toIso8601String() : null,
        ];
    }

    private function decodeFileId(string $id): ?string
    {
        if (!str_starts_with($id, self::FILE_ID_PREFIX)) {
            return null;
        }

        $encoded = strtr(substr($id, strlen(self::FILE_ID_PREFIX)), '-_', '+/');
        $relative = base64_decode($encoded, true);

        if ($relative === false || $relative === '' || str_contains($relative, "\0")
            || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            return null;
        }

        return $relative;
    }

    private function absoluteUrl(string $filename): string
    {
        $filename = str_replace(['{SITE_URL}', '{MEDIA_URL}'], [\site_url(), $this->baseUrl()], $filename);

        if (preg_match('#^https?://#i', $filename)) {
            return $filename;
        }
        if (str_starts_with($filename, '/')) {
            return rtrim(\site_url(), '/') . $filename;
        }

        return $this->baseUrl() . ltrim($filename, '/');
    }

    private function localPath(string $url): ?string
    {
        $prefix = $this->baseUrl();
        if (!str_starts_with($url, $prefix)) {
            return null;
        }

        $relative = rawurldecode((string) (parse_url(substr($url, strlen($prefix)), PHP_URL_PATH) ?? ''));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $base = realpath($this->basePath());
        $path = realpath($this->basePath() . $relative);

        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    private function basePath(): string
    {
        return rtrim(\media_base_path(), '/\\') . DIRECTORY_SEPARATOR;
    }

    private function baseUrl(): string
    {
        return rtrim(\media_base_url(), '/') . '/';
    }
}
