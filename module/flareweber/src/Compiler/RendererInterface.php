<?php

namespace FlareWeber\Compiler;

interface RendererInterface
{
    /**
     * Render a root-relative request path (query string allowed) of the
     * Microweber frontend as an anonymous visitor.
     */
    public function render(string $requestPath): RenderedPage;

    /**
     * Fetch a non-page resource (dynamic thumbnails, generated images).
     * Returns null unless the frontend answers 200 with a non-HTML body
     * no larger than $maxBytes.
     *
     * @return array{content_type: string, body: string}|null
     */
    public function fetch(string $requestPath, int $maxBytes = 26214400): ?array;
}
