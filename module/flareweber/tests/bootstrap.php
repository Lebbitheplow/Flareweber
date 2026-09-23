<?php

/*
 * PHPUnit bootstrap for the FlareWeber module. The module is normally
 * autoloaded by Microweber's module loader, so register its PSR-4 prefix
 * here and pull in the host app's vendor autoloader when one is reachable
 * (FLAREWEBER_APP_PATH, a sibling checkout, or the repo root).
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'FlareWeber\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$candidates = [];
if (($app = getenv('FLAREWEBER_APP_PATH')) !== false && $app !== '') {
    $candidates[] = rtrim($app, '/') . '/vendor/autoload.php';
}
$candidates[] = dirname(__DIR__, 3) . '/vendor/autoload.php';
$candidates[] = dirname(__DIR__, 4) . '/vendor/autoload.php';

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
