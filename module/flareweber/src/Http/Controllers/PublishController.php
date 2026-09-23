<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Deploy\DeploymentInProgressException;
use FlareWeber\Deploy\DeploymentSpawner;
use FlareWeber\Deploy\PublishPipeline;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;
use Illuminate\Routing\Controller;

/**
 * Async publish API (contract F): publish/preview/rollback create a running
 * deployment, start `flareweber:run-deployment` detached and answer 202. If
 * the process cannot be spawned the pipeline runs inline and answers 200.
 */
class PublishController extends Controller
{
    public function publish(PublishPipeline $pipeline, DeploymentSpawner $spawner, Site $site)
    {
        return $this->start($pipeline, $spawner, fn () => $pipeline->publish($site, 'production'));
    }

    public function preview(PublishPipeline $pipeline, DeploymentSpawner $spawner, Site $site)
    {
        return $this->start($pipeline, $spawner, fn () => $pipeline->publish($site, 'preview'));
    }

    public function deployments(Site $site)
    {
        return response()->json(
            $site->deployments()->orderByDesc('id')->limit(50)->get()
                ->map(fn (Deployment $d) => $this->present($d))
        );
    }

    public function show(Site $site, Deployment $deployment)
    {
        abort_unless($deployment->site_id === $site->id, 404);

        return response()->json($this->present($deployment));
    }

    public function rollback(PublishPipeline $pipeline, DeploymentSpawner $spawner, Site $site, Deployment $deployment)
    {
        abort_unless($deployment->site_id === $site->id, 404);

        if ($deployment->status !== 'success' || !$deployment->worker_version_id) {
            return response()->json(['error' => 'not_rollbackable'], 422);
        }

        return $this->start($pipeline, $spawner, fn () => $pipeline->rollback($site, $deployment));
    }

    /** @param callable(): Deployment $create */
    private function start(PublishPipeline $pipeline, DeploymentSpawner $spawner, callable $create)
    {
        try {
            $deployment = $create();
        } catch (DeploymentInProgressException $e) {
            return response()->json([
                'error' => 'deployment_in_progress',
                'deployment' => $this->present($e->running),
            ], 409);
        }

        if ($spawner->spawn($deployment)) {
            return response()->json($this->present($deployment), 202);
        }

        $deployment->appendLog('Background runner unavailable; running inline.');
        $deployment = $pipeline->run($deployment);

        return response()->json($this->present($deployment), 200);
    }

    private function present(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'version' => $deployment->version,
            'environment' => $deployment->environment,
            'status' => $deployment->status,
            'url' => $deployment->url,
            'worker_version_id' => $deployment->worker_version_id,
            'rollback_to' => $deployment->rollback_to,
            'log' => $deployment->log,
            'steps' => $deployment->stepList(),
            'created_at' => $deployment->created_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
        ];
    }
}
