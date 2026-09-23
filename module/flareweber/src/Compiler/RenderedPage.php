<?php

namespace FlareWeber\Compiler;

/**
 * Result of rendering one frontend path.
 */
final class RenderedPage
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $html,
        public readonly string $contentType,
        public readonly ?string $finalPath,
        public readonly ?string $error = null
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(0, null, '', null, $error);
    }

    public function ok(): bool
    {
        return $this->status === 200 && $this->html !== null && $this->isHtml();
    }

    public function isHtml(): bool
    {
        return $this->contentType === '' || str_contains(strtolower($this->contentType), 'html');
    }

    /**
     * True when the render could not even reach the frontend (as opposed to
     * the frontend answering with an error status).
     */
    public function unreachable(): bool
    {
        return $this->error !== null;
    }
}
