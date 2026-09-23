<?php

namespace FlareWeber\Compiler;

use DOMDocument;

/**
 * Everything the crawler learned about the site.
 */
final class CrawlResult
{
    /** @var array<string, DOMDocument> canonical page path => cleaned document */
    public array $pages = [];

    /** @var array<string, string> requested path => canonical path it redirected to */
    public array $aliases = [];

    /** @var array<int, array{from: string, to: string, status: int, nav: bool}> */
    public array $broken = [];

    /** @var array<string, true> canonical paths linked from navigation regions */
    public array $navTargets = [];

    /** @var array<string, true> pages whose Microweber core bundle (with jQuery) was stripped */
    public array $coreStripped = [];

    /** Captured Microweber 404 page (cleaned), when the frontend serves a themed one. */
    public ?DOMDocument $notFound = null;

    public string $renderer = 'loopback';

    public bool $truncated = false;

    /** @var array<int, string> */
    public array $log = [];

    public function isNavTarget(string $path): bool
    {
        return isset($this->navTargets[PagePath::normalize($path)]);
    }

    /**
     * Canonical page path for a requested path, following redirect aliases.
     */
    public function canonical(string $path): string
    {
        $path = PagePath::normalize($path);

        return $this->aliases[$path] ?? $path;
    }
}
