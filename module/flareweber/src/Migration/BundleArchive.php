<?php

namespace FlareWeber\Migration;

use FlareWeber\Deploy\MediaSyncService;
use ZipArchive;

/**
 * Reads and writes the ".zip" site bundle: "site.json" plus a "media/"
 * folder mirroring userfiles/media. Media paths are validated on the way in
 * so a crafted archive cannot write outside the media library.
 */
class BundleArchive
{
    public const JSON_ENTRY = 'site.json';

    public const MEDIA_PREFIX = 'media/';

    /**
     * @param array<string, mixed> $bundle
     * @param string|null $mediaDir when set, every syncable file under it is added as media/{relative}
     * @return int number of media files added
     */
    public function write(string $path, array $bundle, ?string $mediaDir): int
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create archive {$path}.");
        }

        $zip->addFromString(self::JSON_ENTRY, (string) json_encode(
            $bundle,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        $added = 0;
        if ($mediaDir !== null && is_dir($mediaDir)) {
            foreach ($this->mediaFiles($mediaDir) as $relative => $absolute) {
                $zip->addFile($absolute, self::MEDIA_PREFIX . $relative);
                $added++;
            }
        }

        $zip->close();

        return $added;
    }

    /**
     * @return array<string, mixed>
     */
    public function readJson(string $path): array
    {
        $zip = $this->open($path);
        $json = $zip->getFromName(self::JSON_ENTRY);
        $zip->close();

        if ($json === false) {
            throw new \InvalidArgumentException('Archive does not contain ' . self::JSON_ENTRY . '.');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Archive ' . self::JSON_ENTRY . ' is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Extract media entries into $mediaDir (userfiles/media). Existing files
     * are kept unless $overwrite is true.
     *
     * @return array{written: int, skipped: int}
     */
    public function extractMedia(string $path, string $mediaDir, bool $overwrite = false): array
    {
        $zip = $this->open($path);
        $written = 0;
        $skipped = 0;
        $root = rtrim($mediaDir, '/');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, self::MEDIA_PREFIX) || str_ends_with($name, '/')) {
                continue;
            }

            $relative = self::safeRelativePath(substr($name, strlen(self::MEDIA_PREFIX)));
            if ($relative === null || !MediaSyncService::isSyncable($relative)) {
                $skipped++;

                continue;
            }

            $target = $root . '/' . $relative;
            if (is_file($target) && !$overwrite) {
                $skipped++;

                continue;
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                $skipped++;

                continue;
            }

            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                $skipped++;

                continue;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            $written++;
        }

        $zip->close();

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * Normalize a relative path from an archive; null when it escapes the
     * target directory or contains unsafe segments.
     */
    public static function safeRelativePath(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return null;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @return array<string, string> relative => absolute
     */
    private function mediaFiles(string $mediaDir): array
    {
        $files = [];
        $root = rtrim($mediaDir, '/') . '/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            if (MediaSyncService::isSyncable($relative)) {
                $files[$relative] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException("Cannot open archive {$path}.");
        }

        return $zip;
    }
}
