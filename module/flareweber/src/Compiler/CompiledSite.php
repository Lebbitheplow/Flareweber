<?php

namespace FlareWeber\Compiler;

class CompiledSite
{
    public function __construct(
        public readonly string $directory,
        public readonly string $version,
        public readonly array $manifest
    ) {
    }

    public function hash(): string
    {
        return $this->version;
    }

    public function manifestPath(): string
    {
        return $this->directory . '/manifest.json';
    }
}
