<?php
/**
 * Router script for PHP's built-in server (desktop app only).
 *
 * Microweber 2.0.20 is served from the app root, which also holds .env,
 * vendor/, storage/ and friends. Apache honours the root .htaccess that
 * denies them; php -S does not, so this script emulates the same rules:
 *   - deny dotfiles and the private directories (404)
 *   - deny any .php other than the front controller (404)
 *   - refuse Host headers that are not the loopback server (421), which
 *     stops DNS rebinding from reaching the local admin
 *   - serve existing static files as-is (return false), templates and
 *     userfiles need this
 *   - hand everything else (including directories) to index.php the way
 *     Microweber's own server.php does
 *
 * Usage: php -S 127.0.0.1:PORT -t <appDir> /path/to/router.php
 * The decision functions are pure so they can be checked with `php -r`.
 */

const FW_DENIED_DIRS = ['storage', 'vendor', 'config', 'bootstrap', 'database', 'src', 'tests'];

/** Collapse "." and ".." segments; null when the path escapes the root. */
function fw_normalize_path(string $path): ?string
{
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if (!$segments) {
                return null;
            }
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
}

/**
 * Pure allow/deny decision for a request path.
 * Returns 'deny' (404), 'static' (let php -S serve the file) or 'app'
 * (run index.php).
 */
function fw_route_decision(string $rawPath, string $docroot): string
{
    if ($rawPath === '' || str_contains($rawPath, "\0") || str_contains($rawPath, '\\')) {
        return 'deny';
    }

    $path = fw_normalize_path(rawurldecode($rawPath));
    if ($path === null) {
        return 'deny';
    }
    if ($path === '/' || $path === '/index.php') {
        return 'app';
    }

    $segments = explode('/', ltrim($path, '/'));
    foreach ($segments as $segment) {
        if ($segment !== '' && $segment[0] === '.') {
            return 'deny';
        }
    }

    $first = strtolower($segments[0]);
    if (in_array($first, FW_DENIED_DIRS, true)) {
        return 'deny';
    }
    // "/app/..." holds Laravel classes; a bare "/app" may be a CMS page.
    if ($first === 'app' && count($segments) > 1) {
        return 'deny';
    }
    if (preg_match('/\.(php|phtml|phar|php\d)$/i', $path)) {
        return 'deny';
    }

    return is_file($docroot . $path) ? 'static' : 'app';
}

/** Only the loopback names for our own port are accepted as Host. */
function fw_host_allowed(?string $host, string $port): bool
{
    if ($host === null || $port === '') {
        return false;
    }
    $host = strtolower(trim($host));

    return in_array($host, ["127.0.0.1:$port", "localhost:$port", "[::1]:$port"], true);
}

function fw_dispatch(): bool
{
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? getcwd(), '/\\');
    $port = (string) ($_SERVER['SERVER_PORT'] ?? '');

    if (!fw_host_allowed($_SERVER['HTTP_HOST'] ?? null, $port)) {
        http_response_code(421);
        header('Content-Type: text/plain');
        echo "Misdirected request\n";
        return true;
    }

    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $decision = is_string($rawPath) ? fw_route_decision($rawPath, $docroot) : 'deny';

    if ($decision === 'deny') {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "Not found\n";
        return true;
    }
    if ($decision === 'static') {
        return false;
    }

    // Make Laravel see the front controller, not this router, as the script.
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';

    require $docroot . '/index.php';
    return true;
}

if (PHP_SAPI === 'cli-server') {
    return fw_dispatch();
}
