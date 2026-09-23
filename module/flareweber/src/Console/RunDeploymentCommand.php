<?php

namespace FlareWeber\Console;

use FlareWeber\Deploy\PublishPipeline;
use FlareWeber\Models\Deployment;
use Illuminate\Console\Command;

/**
 * Executes a deployment record created by PublishPipeline::publish() or
 * ::rollback(). Spawned detached by PublishController (contract F); can also
 * be run by hand to retry a stuck deployment.
 */
class RunDeploymentCommand extends Command
{
    protected $signature = 'flareweber:run-deployment {deployment : The flare_deployments id}';

    protected $description = 'Run a queued FlareWeber deployment (publish, preview or rollback)';

    public function handle(PublishPipeline $pipeline): int
    {
        $deployment = Deployment::find((int) $this->argument('deployment'));

        if ($deployment === null) {
            $this->error('Deployment not found.');

            return self::FAILURE;
        }

        if ($deployment->status !== 'running') {
            $this->warn("Deployment #{$deployment->id} is {$deployment->status}; nothing to do.");

            return self::SUCCESS;
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $this->info("Running deployment #{$deployment->id} ({$deployment->environment} v{$deployment->version})...");

        $deployment = $pipeline->run($deployment);

        $this->line($deployment->log ?? '');

        if ($deployment->status === 'success') {
            $this->info('Deployment succeeded' . ($deployment->url ? ': ' . $deployment->url : '.'));

            return self::SUCCESS;
        }

        $this->error('Deployment failed.');

        return self::FAILURE;
    }
}
