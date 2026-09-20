<?php

namespace FlareWeber\Deploy;

class DeploymentResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $workerVersionId = null,
        public readonly ?string $url = null,
        public readonly array $log = []
    ) {
    }

    public static function failed(string $message): self
    {
        return new self(false, log: [$message]);
    }
}
