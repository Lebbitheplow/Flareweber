<?php

namespace FlareWeber\Deploy;

use FlareWeber\Models\Deployment;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts `php artisan flareweber:run-deployment {id}` as a detached process
 * (contract F) so the publish request can return 202 immediately. Output goes
 * to a per-deployment log file under storage/app/flareweber/logs.
 */
class DeploymentSpawner
{
    public function spawn(Deployment $deployment): bool
    {
        if (!(bool) config('flareweber.publish.async', true)) {
            return false;
        }

        if (!function_exists('proc_open') || $this->disabled('proc_open')) {
            return false;
        }

        $artisan = base_path('artisan');
        if (!is_file($artisan)) {
            return false;
        }

        $php = $this->phpBinary();
        $logFile = $this->logFile($deployment);

        $command = escapeshellarg($php) . ' ' . escapeshellarg($artisan)
            . ' flareweber:run-deployment ' . (int) $deployment->id;

        try {
            if ($this->isWindows()) {
                $line = 'start /B "" ' . $command . ' > ' . escapeshellarg($logFile) . ' 2>&1';
                $spawn = ['cmd', '/c', $line];
            } else {
                $line = 'nohup ' . $command . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
                $spawn = ['sh', '-c', $line];
            }

            $descriptors = [
                0 => ['file', $this->devNull(), 'r'],
                1 => ['file', $this->devNull(), 'w'],
                2 => ['file', $this->devNull(), 'w'],
            ];

            $process = proc_open($spawn, $descriptors, $pipes, base_path(), null, ['bypass_shell' => true]);

            if (!is_resource($process)) {
                return false;
            }

            $exit = proc_close($process);

            return $exit === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function logFile(Deployment $deployment): string
    {
        $dir = rtrim((string) config('flareweber.publish.log_path', storage_path('app/flareweber/logs')), '/\\');
        File::ensureDirectoryExists($dir);

        return $dir . DIRECTORY_SEPARATOR . 'deployment-' . (int) $deployment->id . '.log';
    }

    private function phpBinary(): string
    {
        try {
            $found = (new PhpExecutableFinder())->find(false);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        } catch (\Throwable) {
            // fall through
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '' && !str_contains(PHP_BINARY, 'php-fpm')) {
            return PHP_BINARY;
        }

        return 'php';
    }

    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';
    }

    private function devNull(): string
    {
        return $this->isWindows() ? 'NUL' : '/dev/null';
    }

    private function disabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }
}
