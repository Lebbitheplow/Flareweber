<?php

namespace FlareWeber\Deploy;

use FlareWeber\Compiler\SiteCompiler;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;

class PublishPipeline
{
    public function __construct(
        private readonly SiteCompiler $compiler,
        private readonly DeploymentProviderInterface $provider
    ) {
    }

    public function publish(Site $site, string $environment = 'production'): Deployment
    {
        $deployment = $this->startDeployment($site, $environment);

        try {
            $this->validate($site, $deployment);
            $this->provision($site, $deployment);

            // Upload media before deploying so rewritten /media/* URLs resolve.
            $mediaViaR2 = $site->requiresR2() && $this->syncMedia($site, $deployment);

            $deployment->appendLog('Compiling site...');
            $compiled = $this->compiler->compile($site, mediaViaR2: $mediaViaR2);
            $deployment->artifact_hash = $compiled->hash();
            $deployment->save();
            $deployment->appendLog("Compiled {$compiled->manifest['product_count']} products, "
                . count($compiled->manifest['routes']) . ' pages.');

            $deployment->appendLog('Building worker bundle...');
            $deployment->appendLog('Deploying via ' . $this->provider->name() . '...');
            $result = $this->provider->deploy($site, $compiled, $environment);

            foreach ($result->log as $line) {
                $deployment->appendLog($line);
            }

            if (!$result->success) {
                throw new \RuntimeException('Deploy failed: ' . ($result->log[0] ?? 'unknown error'));
            }

            $deployment->worker_version_id = $result->workerVersionId;
            $deployment->url = $result->url;
            $deployment->save();

            if ($result->url !== null) {
                $deployment->appendLog('Health check ' . $result->url . '...');
                $healthy = $this->provider->verify($result->url);
                $deployment->appendLog($healthy ? 'Health check passed.' : 'Health check pending (DNS/TLS may still be propagating).');
            }

            $deployment->status = 'success';
            $deployment->finished_at = now();
            $deployment->save();

            if ($environment === 'production') {
                $site->forceFill(['published_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            $deployment->appendLog('FAILED: ' . $e->getMessage());
            $deployment->status = 'failed';
            $deployment->finished_at = now();
            $deployment->save();
        }

        return $deployment;
    }

    public function rollback(Site $site, Deployment $to): Deployment
    {
        $deployment = $this->startDeployment($site, $to->environment);

        $result = $this->provider->rollback($site, (string) $to->worker_version_id);

        foreach ($result->log as $line) {
            $deployment->appendLog($line);
        }

        $deployment->status = $result->success ? 'success' : 'failed';
        $deployment->worker_version_id = $to->worker_version_id;
        $deployment->url = $result->url;
        $deployment->artifact_hash = $to->artifact_hash;
        $deployment->finished_at = now();
        $deployment->save();

        return $deployment;
    }

    private function startDeployment(Site $site, string $environment): Deployment
    {
        $version = (int) $site->deployments()->where('environment', $environment)->max('version') + 1;

        $deployment = $site->deployments()->create([
            'version' => $version,
            'environment' => $environment,
            'status' => 'running',
        ]);

        return $deployment;
    }

    private function validate(Site $site, Deployment $deployment): void
    {
        $deployment->appendLog('Validating...');

        if ($site->cloudflareConnection === null) {
            throw new \RuntimeException('Connect a Cloudflare account before publishing.');
        }

        if ($site->requiresEcommerce() && empty($site->settings['stripe_account_id'])) {
            throw new \RuntimeException('Ecommerce site has no Stripe account connected.');
        }
    }

    private function provision(Site $site, Deployment $deployment): void
    {
        $deployment->appendLog('Provisioning Cloudflare resources...');
        $resources = $this->provider->provision($site);
        $deployment->appendLog('Worker: ' . ($resources['worker']['script_name'] ?? 'ok')
            . ', D1: ' . ($resources['d1'] ? 'yes' : 'none')
            . ', R2: ' . ($resources['r2'] ? 'yes' : 'none'));
    }

    /**
     * Upload new/changed media to R2 before deploy so compiled pages can
     * reference /media/*. Returns true when the bucket is live for this site.
     */
    private function syncMedia(Site $site, Deployment $deployment): bool
    {
        try {
            $summary = $this->provider->syncMedia($site);

            if ($summary === null) {
                $deployment->appendLog('Media: R2 bucket not provisioned; media stays in the asset bundle.');

                return false;
            }

            $deployment->appendLog('Syncing media library to R2...');
            $deployment->appendLog(sprintf(
                'Media: %d uploaded, %d unchanged, %d removed%s.',
                $summary['uploaded'],
                $summary['unchanged'],
                $summary['deleted'],
                $summary['skipped'] > 0 ? ", {$summary['skipped']} over size limit" : ''
            ));

            return true;
        } catch (\Throwable $e) {
            $deployment->appendLog('Media sync failed (media will 404 until fixed): ' . $e->getMessage());

            return false;
        }
    }
}
