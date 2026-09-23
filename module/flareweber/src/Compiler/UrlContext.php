<?php

namespace FlareWeber\Compiler;

/**
 * Immutable description of the URL environment a compile runs in: the local
 * Microweber origin being crawled, the public site URL (when known) and
 * whether uploaded media is served from R2.
 */
final class UrlContext
{
    public const MEDIA_SOURCE_PREFIX = '/userfiles/media/';

    public const MEDIA_TARGET_PREFIX = '/media/';

    public const PAGINATION_PARAMS = ['page', 'current_page'];

    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $publicUrl,
        public readonly bool $mediaViaR2
    ) {
    }

    public static function fromBaseUrl(string $baseUrl, ?string $publicUrl = null, bool $mediaViaR2 = false): self
    {
        $parts = parse_url($baseUrl) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = strtolower((string) ($parts['host'] ?? 'localhost'));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $public = $publicUrl !== null ? rtrim(trim($publicUrl), '/') : null;
        if ($public === '' || ($public !== null && !preg_match('#^https?://#i', $public))) {
            $public = null;
        }

        return new self($scheme, $host, $port, $public, $mediaViaR2);
    }

    /**
     * Origin used for loopback requests, e.g. http://127.0.0.1:8471.
     */
    public function origin(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->scheme . '://' . $this->host . ($this->port === $default ? '' : ':' . $this->port);
    }

    /**
     * True when an absolute URL points at the local origin: scheme, host and
     * port must all match exactly. Protocol-relative URLs are external.
     */
    public function isLocal(string $url): bool
    {
        if (str_starts_with($url, '//')) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme === $this->scheme
            && strtolower($parts['host']) === $this->host
            && $port === $this->port;
    }

    /**
     * Map a root-relative media path to its served location.
     */
    public function mediaTarget(string $path): string
    {
        if ($this->mediaViaR2 && str_starts_with($path, self::MEDIA_SOURCE_PREFIX)) {
            return self::MEDIA_TARGET_PREFIX . substr($path, strlen(self::MEDIA_SOURCE_PREFIX));
        }

        return $path;
    }

    public function isMediaPath(string $path): bool
    {
        return str_starts_with($path, self::MEDIA_SOURCE_PREFIX) && strlen($path) > strlen(self::MEDIA_SOURCE_PREFIX);
    }

    /**
     * Absolute public URL for a compiled page path when the public origin is
     * known, otherwise the root-relative path.
     */
    public function absolute(string $rootRelative): string
    {
        return $this->publicUrl !== null ? $this->publicUrl . $rootRelative : $rootRelative;
    }
}
