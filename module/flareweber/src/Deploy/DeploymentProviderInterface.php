<?php

namespace FlareWeber\Deploy;

use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;

interface DeploymentProviderInterface
{
    public function name(): string;

    /**
     * Create or look up the hosting resources for a site and persist their
     * identifiers on the site.
     *
     * @return array{worker: array, d1: array|null, r2: array|null}
     */
    public function provision(Site $site): array;

    /**
     * Upload the compiled site to the given environment (production|preview),
     * configure secrets and seed the database. Step outcomes are returned in
     * DeploymentResult::$steps.
     */
    public function deploy(Site $site, CompiledSite $compiled, string $environment): DeploymentResult;

    /** Re-point the environment of $to at its recorded worker version. */
    public function rollback(Site $site, Deployment $to): DeploymentResult;

    /**
     * Health details for a deployed URL:
     * {ok, index, api, db, media, https, version, detail}.
     *
     * @return array<string, mixed>
     */
    public function verify(Site $site, string $url): array;

    /**
     * Sync the local media library into the provider's object store (R2).
     * Returns counts, or null when the site does not use object storage.
     *
     * @return array{uploaded: int, unchanged: int, deleted: int, skipped: int}|null
     */
    public function syncMedia(Site $site): ?array;
}
