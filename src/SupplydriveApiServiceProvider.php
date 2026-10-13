<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

use Darvis\SupplydriveApi\Console\Commands\MessageStatusCommand;
use Illuminate\Support\ServiceProvider;

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
    }
}
