<?php

use Darvis\SupplydriveApi\Console\Commands\HealthCommand;
use Darvis\SupplydriveApi\Console\Commands\MessageStatusCommand;
use Darvis\SupplydriveApi\Console\Commands\SendDocumentCommand;
use Darvis\SupplydriveApi\SupplydriveApiServiceProvider;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;

it('registers the client as a singleton with the supplydrive alias', function () {
    expect(app(SupplydriveClient::class))->toBe(app(SupplydriveClient::class))
        ->and(app('supplydrive'))->toBe(app(SupplydriveClient::class));
});

it('merges the package config', function () {
    expect(config('supplydrive.base_url'))->toBe('https://portal.supplydrive.com')
        ->and(config('supplydrive.client_id'))->toBe('SupplydrivePortal');
});

it('can be resolved without an account, so route:list and container warm-ups do not fail', function () {
    config(['supplydrive.username' => null, 'supplydrive.password' => null]);

    app()->forgetInstance(SupplydriveClient::class);

    expect(app(SupplydriveClient::class))->toBeInstanceOf(SupplydriveClient::class);
});

it('does not build the client until it is used', function () {
    (new SupplydriveApiServiceProvider(app()))->register();

    expect(app()->resolved(SupplydriveClient::class))->toBeFalse();
});

it('registers the supplydrive:status command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('supplydrive:status')
        ->and(app(Kernel::class)->all()['supplydrive:status'])->toBeInstanceOf(MessageStatusCommand::class);
});

it('registers the sender as a singleton with the supplydrive.sender alias, without a send account', function () {
    config(['supplydrive.send.username' => null, 'supplydrive.send.password' => null]);

    app()->forgetInstance(SupplydriveSender::class);

    expect(app(SupplydriveSender::class))->toBe(app(SupplydriveSender::class))
        ->and(app('supplydrive.sender'))->toBe(app(SupplydriveSender::class));
});

it('registers the supplydrive:send command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('supplydrive:send')
        ->and(app(Kernel::class)->all()['supplydrive:send'])->toBeInstanceOf(SendDocumentCommand::class);
});

it('loads the status descriptions in English and Dutch', function () {
    expect(trans('supplydrive-api::status.delivered', [], 'en'))->toBe('The message has been delivered.')
        ->and(trans('supplydrive-api::status.delivered', [], 'nl'))->toBe('Het bericht is succesvol afgeleverd.');
});

it('offers the config file and the translations for publishing', function () {
    // Never run vendor:publish here: it writes into the Testbench app.
    $config = ServiceProvider::pathsToPublish(SupplydriveApiServiceProvider::class, 'supplydrive-api-config');

    expect($config)->toHaveCount(1)
        ->and(array_key_first($config))->toEndWith('config/supplydrive.php')
        ->and(array_values($config)[0])->toBe(config_path('supplydrive.php'));

    $lang = ServiceProvider::pathsToPublish(SupplydriveApiServiceProvider::class, 'supplydrive-api-lang');

    expect($lang)->toHaveCount(1)
        ->and(array_key_first($lang))->toEndWith('resources/lang')
        ->and(array_values($lang)[0])->toBe(app()->langPath('vendor/supplydrive-api'));
});

it('registers the supplydrive:health command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('supplydrive:health')
        ->and(app(Kernel::class)->all()['supplydrive:health'])->toBeInstanceOf(HealthCommand::class);
});
