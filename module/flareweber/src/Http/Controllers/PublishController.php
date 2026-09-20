<?php

namespace FlareWeber\Http\Controllers;

use FlareWeber\Deploy\PublishPipeline;
use FlareWeber\Models\Deployment;
use FlareWeber\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublishController extends Controller
{
    public function publish(Request $request, PublishPipeline $pipeline, Site $site)
    {
        $deployment = $pipeline->publish($site, 'production');

        return response()->json($this->present($deployment), $deployment->status === 'success' ? 200 : 502);
    }

    public function preview(PublishPipeline $pipeline, Site $site)
    {
        if (!$site->preview_worker_name) {
            $site->forceFill([
                'preview_worker_name' => ($site->worker_name ?: 'flareweber-' . $site->id) . '--preview',
            ])->save();
        }

        $deployment = $pipeline->publish($site, 'preview');

        return response()->json($this->present($deployment), $deployment->status === 'success' ? 200 : 502);
    }

    public function deployments(Site $site)
    {
        return response()->json(
            $site->deployments()->latest('version')->get()
                ->map(fn (Deployment $d) => $this->present($d))
        );
    }

    public function rollback(Request $request, PublishPipeline $pipeline, Site $site, Deployment $deployment)
    {
        abort_unless($deployment->site_id === $site->id, 404);
        abort_unless($deployment->status === 'success', 422);

        $result = $pipeline->rollback($site, $deployment);

        return response()->json($this->present($result), $result->status === 'success' ? 200 : 502);
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
            'log' => $deployment->log,
        ];
    }
}
