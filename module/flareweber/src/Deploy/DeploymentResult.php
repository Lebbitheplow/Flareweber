<?php

namespace FlareWeber\Deploy;

class DeploymentResult
{
    /**
     * @param array<int, string> $log
     * @param array<string, array{status: string, detail: string|null}> $steps
     *        per-step outcome keyed by step key (upload, secrets, seed, ...)
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $workerVersionId = null,
        public readonly ?string $url = null,
        public readonly array $log = [],
        public readonly array $steps = [],
        public readonly ?string $error = null
    ) {
    }

    /**
     * @param array<string, array{status: string, detail: string|null}> $steps
     */
    public static function failed(string $message, array $steps = [], ?string $workerVersionId = null): self
    {
        return new self(false, $workerVersionId, null, [$message], $steps, $message);
    }
}
