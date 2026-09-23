<?php

namespace FlareWeber\Deploy;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Bundles the Worker template's TypeScript entry into a single ESM file via
 * esbuild, so deploys do not depend on a TypeScript toolchain at the target
 * and the deploy artifact is a single module (what the Workers REST upload
 * expects). Falls back gracefully when esbuild is unavailable.
 */
class WorkerBundler
{
    public function __construct(
        private readonly string $templatePath
    ) {
    }

    /**
     * Bundle src/index.ts into $outputDir/worker.mjs.
     *
     * @return string|null absolute path to the bundle, or null on failure
     */
    public function bundle(string $outputDir): ?string
    {
        $entry = rtrim($this->templatePath, '/') . '/src/index.ts';
        if (!is_file($entry)) {
            return null;
        }

        File::ensureDirectoryExists($outputDir);
        $output = rtrim($outputDir, '/') . '/worker.mjs';

        $command = sprintf(
            '%s %s --bundle --format=esm --platform=neutral --outfile=%s --minify --log-level=error',
            escapeshellcmd($this->esbuild()),
            escapeshellarg($entry),
            escapeshellarg($output)
        );

        $process = Process::timeout(120)
            ->path(rtrim($this->templatePath, '/'))
            ->run($command);

        if (!$process->successful() || !is_file($output)) {
            return null;
        }

        return $output;
    }

    /**
     * Whether any file under the template's src/ (or its schema.sql) is newer
     * than the given prebuilt bundle, meaning the bundle is stale.
     */
    public function sourceNewerThan(string $bundle): bool
    {
        if (!is_file($bundle)) {
            return true;
        }

        $built = (int) filemtime($bundle);
        $src = rtrim($this->templatePath, '/') . '/src';

        if (!is_dir($src)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && (int) $file->getMTime() > $built) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer the project-local esbuild binary, then a global one via npx.
     */
    private function esbuild(): string
    {
        $local = rtrim($this->templatePath, '/') . '/node_modules/.bin/esbuild';
        if (is_file($local) || is_link($local)) {
            return $local;
        }

        return 'npx --yes esbuild@0.25';
    }
}
