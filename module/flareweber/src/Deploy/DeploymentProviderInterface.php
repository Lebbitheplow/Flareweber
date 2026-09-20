<?php

namespace FlareWeber\Deploy;

use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\Site;

interface DeploymentProviderInterface
{
    public function name(): string;

    public function provision(Site $site): array;

    public function deploy(Site $site, CompiledSite $compiled, string $environment): DeploymentResult;

    public function rollback(Site $site, string $workerVersionId): DeploymentResult;

    /**
     * Sync the local media library into the provider's object store (R2).
     * Returns counts, or null when the site does not use object storage.
     *
     * @return array{uploaded: int, deleted: int, unchanged: int, skipped: int, bytes: int}|null
     */
    public function syncMedia(Site $site): ?array;

    public function verify(string $url): bool;
}
