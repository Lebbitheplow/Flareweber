<?php

namespace FlareWeber\Deploy;

use FlareWeber\Compiler\CompiledSite;
use FlareWeber\Compiler\SiteCompiler;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;
use FlareWeber\Stripe\StripeConnectService;
use FlareWeber\Support\Handoff;

/**
 * Publish orchestration (contract F). publish()/rollback() only create the
 * Deployment record; run() executes it step by step, persisting `steps` and
 * `log` after every step so the SPA can poll progress.
 */
class PublishPipeline
{
    private const HEALTH_ATTEMPTS = 6;

    private const HEALTH_DELAY_SECONDS = 5;

    private const DOMAIN_WAIT_SECONDS = 60;

    public function __construct(
        private readonly SiteCompiler $compiler,
        private readonly DeploymentProviderInterface $provider,
        private readonly PublishValidator $validator
    ) {
    }

    /**
     * Create a running deployment for the environment. Throws
     * DeploymentInProgressException when one is already running.
     */
    public function publish(Site $site, string $environment = 'production'): Deployment
    {
        $this->guardNotRunning($site);

        if ($environment === 'preview' && !$site->preview_worker_name) {
            $site->forceFill(['preview_worker_name' => $site->previewWorkerName()])->save();
        }

        return $this->startDeployment($site, $environment);
    }

    /** Create a running rollback deployment targeting a previous success. */
    public function rollback(Site $site, Deployment $to): Deployment
    {
        $this->guardNotRunning($site);

        return $this->startDeployment($site, $to->environment ?: 'production', $to);
    }

    /** Execute a deployment created by publish() or rollback(). */
    public function run(Deployment $deployment): Deployment
    {
        $deployment->refresh();
        $site = $deployment->site;

        if ($site === null) {
            $deployment->appendLog('FAILED: site no longer exists.');
            $this->finish($deployment, 'failed');

            return $deployment;
        }

        if ($deployment->isRollback()) {
            return $this->runRollback($site, $deployment);
        }

        $environment = $deployment->environment ?: 'production';
        $previous = $environment === 'production' ? $this->lastSuccessful($site, $deployment) : null;

        try {
            $this->step($deployment, 'validate', fn () => $this->validate($site, $deployment));
            $this->step($deployment, 'provision', fn () => $this->provision($site, $deployment, $environment));

            $mediaViaR2 = $this->mediaStep($site, $deployment);

            $compiled = null;
            $this->step($deployment, 'compile', function () use ($site, $deployment, $mediaViaR2, &$compiled) {
                $compiled = $this->compiler->compile($site, mediaViaR2: $mediaViaR2);
                $deployment->forceFill(['artifact_hash' => $compiled->hash()])->save();
                $this->validator->validateCompiled($compiled, $deployment);

                return sprintf(
                    '%d pages, %d products',
                    count($compiled->manifest['routes'] ?? []),
                    (int) ($compiled->manifest['product_count'] ?? 0)
                );
            });

            $result = $this->deployStep($site, $deployment, $compiled, $environment);

            $this->step($deployment, 'health', fn () => $this->healthCheck($deployment, $site, (string) $result->url, $environment));
            $this->domainStep($site, $deployment, $environment);

            $this->finish($deployment, 'success');

            if ($environment === 'production') {
                $site->forceFill(['published_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            $deployment->appendLog('FAILED: ' . $e->getMessage());
            $deployment->finishRemainingSteps('skipped');
            $this->autoRollback($site, $deployment, $previous);
            $this->finish($deployment, 'failed');
        }

        return $deployment;
    }

    private function runRollback(Site $site, Deployment $deployment): Deployment
    {
        $to = $deployment->rollbackTarget;

        foreach (['validate', 'provision', 'media', 'compile', 'secrets', 'seed'] as $key) {
            $deployment->setStep($key, 'skipped', 'Not needed for a rollback');
        }

        try {
            if ($to === null || $to->status !== 'success' || !$to->worker_version_id) {
                throw new \RuntimeException('Rollback target is not a successful deployment.');
            }

            $deployment->setStep('upload', 'running');
            $deployment->appendLog("Rolling back to version {$to->version}...");
            $result = $this->provider->rollback($site, $to);
            $this->applyResult($deployment, $result);

            $deployment->forceFill([
                'worker_version_id' => $to->worker_version_id,
                'artifact_hash' => $to->artifact_hash,
                'url' => $result->url,
            ])->save();

            $this->step($deployment, 'health', fn () => $this->healthCheck($deployment, $site, (string) $result->url, $deployment->environment));
            $this->domainStep($site, $deployment, $deployment->environment);
            $this->finish($deployment, 'success');
        } catch (\Throwable $e) {
            $deployment->appendLog('FAILED: ' . $e->getMessage());
            $deployment->finishRemainingSteps('skipped');
            $this->finish($deployment, 'failed');
        }

        return $deployment;
    }

    /**
     * Run one step: mark running, execute, mark done with the returned
     * detail. Exceptions mark the step failed and propagate.
     */
    private function step(Deployment $deployment, string $key, callable $fn): mixed
    {
        $deployment->setStep($key, 'running');
        $deployment->appendLog(Deployment::STEPS[$key] . '...');

        try {
            $detail = $fn();
        } catch (\Throwable $e) {
            $deployment->setStep($key, 'failed', Handoff::sanitize($e->getMessage(), 300));

            throw $e;
        }

        if (is_array($detail) && ($detail['status'] ?? null) !== null) {
            $deployment->setStep($key, $detail['status'], $detail['detail'] ?? null);

            return $detail;
        }

        $deployment->setStep($key, 'done', is_string($detail) ? $detail : null);

        return $detail;
    }

    private function validate(Site $site, Deployment $deployment): string
    {
        if ($site->cloudflareConnection === null) {
            throw new \RuntimeException('Connect a Cloudflare account before publishing.');
        }

        if (!empty($site->settings['skip_validation'])) {
            return 'Validation skipped by site setting';
        }

        foreach ($this->validator->warnings($site) as $warning) {
            $deployment->appendLog('Warning: ' . $warning);
        }

        $errors = $this->validator->errors($site);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        return 'OK';
    }

    private function provision(Site $site, Deployment $deployment, string $environment): string
    {
        $resources = $this->provider->provision($site);
        $site->refresh();

        $detail = 'Worker ' . ($resources['worker']['script_name'] ?? $site->worker_name)
            . ', D1 ' . ($resources['d1'] ? ($site->d1_database_name ?: 'yes') : 'none')
            . ', R2 ' . ($resources['r2'] ? ($site->r2_bucket_name ?: 'yes') : 'none');

        if ($environment === 'production' && $site->requiresEcommerce()) {
            $secret = app(StripeConnectService::class)->registerWebhook($site);
            $deployment->appendLog($secret !== null
                ? 'Stripe webhook endpoint registered.'
                : 'Stripe webhook could not be registered (no site URL yet).');
        }

        return $detail;
    }

    /** Upload new/changed media to R2. Returns true when /media/* is served from R2. */
    private function mediaStep(Site $site, Deployment $deployment): bool
    {
        if (!$site->requiresR2()) {
            $deployment->setStep('media', 'skipped', 'Media stays in the asset bundle (R2 disabled)');

            return false;
        }

        $summary = $this->step($deployment, 'media', function () use ($site) {
            $summary = $this->provider->syncMedia($site);

            if ($summary === null) {
                return ['status' => 'skipped', 'detail' => 'R2 bucket not provisioned; media stays in the asset bundle'];
            }

            return ['status' => 'done', 'detail' => sprintf(
                '%d uploaded, %d unchanged, %d removed%s',
                $summary['uploaded'],
                $summary['unchanged'],
                $summary['deleted'],
                ($summary['skipped'] ?? 0) > 0 ? ", {$summary['skipped']} skipped" : ''
            )];
        });

        return ($summary['status'] ?? null) === 'done';
    }

    private function deployStep(Site $site, Deployment $deployment, CompiledSite $compiled, string $environment): DeploymentResult
    {
        $deployment->setStep('upload', 'running');
        $deployment->appendLog('Deploying via ' . $this->provider->name() . '...');

        $result = $this->provider->deploy($site, $compiled, $environment);
        $this->applyResult($deployment, $result);

        if (!$result->success) {
            throw new \RuntimeException($result->error ?? 'Deploy failed');
        }

        $deployment->forceFill([
            'worker_version_id' => $result->workerVersionId,
            'url' => $result->url,
        ])->save();

        return $result;
    }

    private function applyResult(Deployment $deployment, DeploymentResult $result): void
    {
        foreach ($result->log as $line) {
            $deployment->appendLog($line);
        }

        foreach ($result->steps as $key => $step) {
            $deployment->setStep($key, $step['status'], $step['detail'] ?? null);
        }
    }

    /**
     * Contract B checks 1 and 2 against the workers.dev URL. Production
     * failures throw (which triggers the automatic rollback); previews only
     * record the outcome.
     */
    private function healthCheck(Deployment $deployment, Site $site, string $url, string $environment): array
    {
        if ($url === '') {
            throw new \RuntimeException('No URL to health check (workers.dev subdomain unknown).');
        }

        $health = ['ok' => false, 'detail' => 'not checked'];

        for ($attempt = 1; $attempt <= self::HEALTH_ATTEMPTS; $attempt++) {
            $health = $this->provider->verify($site, $url);

            if ($health['ok']) {
                break;
            }

            if ($attempt < self::HEALTH_ATTEMPTS) {
                sleep(self::HEALTH_DELAY_SECONDS);
            }
        }

        $detail = $url . ': ' . ($health['detail'] ?? '');

        if ($health['ok']) {
            $deployment->appendLog('Health check passed at ' . $url);

            return ['status' => 'done', 'detail' => $detail];
        }

        if ($environment === 'production') {
            throw new \RuntimeException('Health check failed at ' . $detail);
        }

        $deployment->appendLog('Health check pending at ' . $detail);

        return ['status' => 'pending', 'detail' => $detail];
    }

    /** Contract B checks 3 and 4: the custom domain, polled for up to 60s. */
    private function domainStep(Site $site, Deployment $deployment, string $environment): void
    {
        if ($environment !== 'production' || empty($site->domain)) {
            $deployment->setStep('domain', 'skipped', $environment !== 'production' ? 'Previews use workers.dev only' : 'No custom domain');

            return;
        }

        $url = 'https://' . $site->domain . '/';
        $deployment->setStep('domain', 'running');
        $deployment->appendLog('Checking custom domain ' . $url . '...');

        $deadline = time() + self::DOMAIN_WAIT_SECONDS;
        $health = ['ok' => false, 'detail' => 'not checked'];

        do {
            $health = $this->provider->verify($site, $url);

            if ($health['ok']) {
                break;
            }

            sleep(self::HEALTH_DELAY_SECONDS);
        } while (time() < $deadline);

        if ($health['ok']) {
            $https = !empty($health['https']) ? 'HTTPS ready' : 'reachable';
            $deployment->setStep('domain', 'done', $url . ' ' . $https);
            $deployment->forceFill(['url' => rtrim($url, '/')])->save();
            $deployment->appendLog('Custom domain live at ' . $url);

            return;
        }

        $deployment->setStep('domain', 'pending', 'Not reachable yet (' . ($health['detail'] ?? '') . '); DNS or the certificate may still be propagating');
        $deployment->appendLog('Custom domain pending: ' . ($health['detail'] ?? ''));
    }

    /**
     * Restore the last successful production version after a failed publish.
     * D1 data and R2 media are shared across versions and stay as-is; only
     * the Worker bundle (code + static assets) is reverted.
     */
    private function autoRollback(Site $site, Deployment $deployment, ?Deployment $previous): void
    {
        if ($previous === null || $deployment->worker_version_id === null) {
            return; // nothing was uploaded, or nothing to go back to
        }

        $deployment->appendLog('Rolling back to version ' . $previous->version . '...');

        try {
            $result = $this->provider->rollback($site, $previous);

            foreach ($result->log as $line) {
                $deployment->appendLog($line);
            }

            $deployment->appendLog($result->success
                ? 'Rolled back to version ' . $previous->version . '.'
                : 'Automatic rollback FAILED: ' . ($result->error ?? 'unknown error'));
        } catch (\Throwable $e) {
            $deployment->appendLog('Automatic rollback failed: ' . $e->getMessage());
        }
    }

    private function lastSuccessful(Site $site, Deployment $current): ?Deployment
    {
        return $site->deployments()
            ->where('environment', 'production')
            ->where('status', 'success')
            ->where('id', '!=', $current->id)
            ->whereNotNull('worker_version_id')
            ->orderByDesc('version')
            ->first();
    }

    private function guardNotRunning(Site $site): void
    {
        $this->failStaleDeployments($site);

        $running = $site->runningDeployment();

        if ($running !== null) {
            throw new DeploymentInProgressException($running);
        }
    }

    /** Running deployments that stopped reporting are marked failed. */
    private function failStaleDeployments(Site $site): void
    {
        $minutes = max(1, (int) config('flareweber.publish.stale_minutes', 30));

        $stale = $site->deployments()
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes($minutes))
            ->get();

        foreach ($stale as $deployment) {
            $deployment->appendLog("FAILED: no progress for {$minutes} minutes; marked as abandoned.");
            $deployment->finishRemainingSteps('failed', 'Abandoned');
            $this->finish($deployment, 'failed');
        }
    }

    private function startDeployment(Site $site, string $environment, ?Deployment $rollbackTo = null): Deployment
    {
        $version = (int) $site->deployments()->where('environment', $environment)->max('version') + 1;

        return $site->deployments()->create([
            'version' => $version,
            'environment' => $environment,
            'status' => 'running',
            'steps' => Deployment::initialSteps(),
            'rollback_to' => $rollbackTo?->id,
            'log' => $rollbackTo
                ? date('[H:i:s] ') . "Queued rollback to version {$rollbackTo->version}.\n"
                : date('[H:i:s] ') . "Queued {$environment} deployment.\n",
        ]);
    }

    private function finish(Deployment $deployment, string $status): void
    {
        $deployment->forceFill(['status' => $status, 'finished_at' => now()])->save();
    }
}
