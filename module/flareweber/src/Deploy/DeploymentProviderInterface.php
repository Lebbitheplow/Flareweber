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

    public function verify(string $url): bool;
}
