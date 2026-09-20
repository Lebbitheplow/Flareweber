<?php

namespace FlareWeber\Providers;

use FlareWeber\Deploy\DeploymentProviderInterface;
use FlareWeber\Deploy\PublishPipeline;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FlareWeberServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/flareweber.php', 'flareweber');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->bind(DeploymentProviderInterface::class, \FlareWeber\Deploy\CloudflareWorkersProvider::class);
        $this->app->bind(PublishPipeline::class, function ($app) {
            return new PublishPipeline(
                $app->make(\FlareWeber\Compiler\SiteCompiler::class),
                $app->make(DeploymentProviderInterface::class)
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'flareweber');

        Route::prefix('flareweber')
            ->name('flareweber.')
            ->middleware('web')
            ->group(__DIR__ . '/../routes/web.php');
    }
}
