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
        $this->mergeConfigFrom(__DIR__ . '/../../config/flareweber.php', 'flareweber');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->app->bind(DeploymentProviderInterface::class, \FlareWeber\Deploy\CloudflareWorkersProvider::class);
        $this->app->bind(PublishPipeline::class, function ($app) {
            return new PublishPipeline(
                $app->make(\FlareWeber\Compiler\SiteCompiler::class),
                $app->make(DeploymentProviderInterface::class),
                $app->make(\FlareWeber\Deploy\PublishValidator::class)
            );
        });

        $this->commands([
            \FlareWeber\Console\ExportSitesCommand::class,
            \FlareWeber\Console\ImportSitesCommand::class,
            \FlareWeber\Console\RunDeploymentCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'flareweber');

        Route::prefix('flareweber')
            ->name('flareweber.')
            ->middleware(['web', \FlareWeber\Http\Middleware\EnsureAdminAuthenticated::class])
            ->group(__DIR__ . '/../../routes/web.php');

        // OAuth round-trip: the callback lands from the system browser, which
        // carries no admin session, so it is authenticated by its state token.
        Route::prefix('flareweber')
            ->name('flareweber.')
            ->middleware('web')
            ->group(__DIR__ . '/../../routes/oauth.php');

        // JSON API consumed by the FlareWeber admin SPA (pages, products,
        // media, orders, dashboard). Same auth gate as the web routes.
        if (is_file(__DIR__ . '/../../routes/admin.php')) {
            Route::prefix('flareweber/api')
                ->name('flareweber.api.')
                ->middleware(['web', \FlareWeber\Http\Middleware\EnsureAdminAuthenticated::class])
                ->group(__DIR__ . '/../../routes/admin.php');
        }

        // Server-to-server callbacks: no session/CSRF middleware.
        Route::prefix('flareweber/webhooks')
            ->name('flareweber.webhooks.')
            ->group(__DIR__ . '/../../routes/webhooks.php');

        $this->registerAdminMenu();
    }

    /**
     * Add the FlareWeber admin SPA to the Microweber admin menu when running
     * inside Microweber. Guarded so a Microweber API change never breaks the
     * module (the SPA stays reachable at /flareweber/admin regardless).
     */
    private function registerAdminMenu(): void
    {
        $manager = '\MicroweberPackages\AdminManager\Facades\AdminManager';
        $linkClass = '\MicroweberPackages\AdminManager\MenuTypes\MenuLink';

        if (! class_exists($manager) || ! class_exists($linkClass)) {
            return;
        }

        try {
            $menu = new $linkClass();
            $menu->setName('FlareWeber')
                ->setUri('/flareweber/admin')
                ->setIcon('zap')
                ->setPosition(10)
                ->setMenuLocation('secondary_sidebar_top');

            $manager::registerMenu($menu);
        } catch (\Throwable) {
            // Menu registration is best-effort.
        }
    }
}
