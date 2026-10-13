<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

use Darvis\SupplydriveApi\Console\Commands\MessageStatusCommand;
use Darvis\SupplydriveApi\Mcp\SupplydriveServer;
use Darvis\SupplydriveApi\Support\SupplydriveConfig;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Registrar;

class SupplydriveApiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/supplydrive.php', 'supplydrive');

        $this->app->singleton(SupplydriveClient::class);
        $this->app->alias(SupplydriveClient::class, 'supplydrive');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'supplydrive-api');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/supplydrive.php' => config_path('supplydrive.php'),
            ], 'supplydrive-api-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/supplydrive-api'),
            ], 'supplydrive-api-lang');

            $this->commands([
                MessageStatusCommand::class,
            ]);
        }

        $this->registerMcpServer();
    }

    /**
     * Register the local MCP server when laravel/mcp is installed. It stays optional so the
     * package installs on every Laravel version without it.
     */
    protected function registerMcpServer(): void
    {
        if (! SupplydriveConfig::mcpEnabled() || ! class_exists(Registrar::class)) {
            return;
        }

        $this->callAfterResolving(Registrar::class, function (Registrar $registrar): void {
            $registrar->local(SupplydriveConfig::mcpHandle(), SupplydriveServer::class);
        });
    }
}
