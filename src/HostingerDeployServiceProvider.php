<?php

namespace Zura\HostingerDeploy;

use Illuminate\Support\ServiceProvider;
use Zura\HostingerDeploy\Commands\DeploySharedCommand;
use Zura\HostingerDeploy\Commands\PublishWorkflowCommand;
use Zura\HostingerDeploy\Commands\AutoDeployCommand;
use Zura\HostingerDeploy\Commands\SetupEnvCommand;
use Zura\HostingerDeploy\Commands\SetupAutomatedDeployCommand;
use Zura\HostingerDeploy\Commands\DeployAndSetupAutomatedCommand;

class HostingerDeployServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/hostinger-deploy.php', 'hostinger-deploy'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DeploySharedCommand::class,
                PublishWorkflowCommand::class,
                AutoDeployCommand::class,
                SetupEnvCommand::class,
                SetupAutomatedDeployCommand::class,
                DeployAndSetupAutomatedCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/config/hostinger-deploy.php' => config_path('hostinger-deploy.php'),
            ], 'hostinger-deploy-config');

            $this->publishes([
                __DIR__.'/../stubs/hostinger-deploy.yml' => base_path('.github/workflows/hostinger-deploy.yml'),
            ], 'hostinger-deploy-workflow');
        }
    }
}
