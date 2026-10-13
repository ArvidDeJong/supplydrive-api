<?php

use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('prints the messages as a table', function () {
    app()->setLocale('en');
    $this->fakeSupplydrive([TestCase::message('Afgeleverd', 'F260001', 1)]);

    $this->artisan('supplydrive:status', ['--functional-id' => 'F260001'])
        ->expectsOutput('Looking up messages at Supplydrive...')
        ->expectsOutput('✓ Connection successful, 1 message(s) found.')
        ->expectsTable(
            ['Id', 'Functional id', 'Status', 'Interface', 'Description'],
            [[1, 'F260001', 'Afgeleverd', 1000, 'The message has been delivered.']],
        )
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/SD/Berichten/Status?functioneelId=F260001'));
});

it('maps every option to its filter', function () {
    $this->fakeSupplydrive([]);

    $this->artisan('supplydrive:status', [
        '--message-id' => '12',
        '--kvk' => '12345678',
        '--gln' => '8712345678906',
        '--vat' => 'NL123456789B01',
    ])->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_ends_with(
        $request->url(),
        '/SD/Berichten/Status?berichtId=12&debiteurKVK=12345678&debiteurGLN=8712345678906&debiteurBTW=NL123456789B01'
    ));
});

it('says so when nothing matches, and still exits with success', function () {
    $this->fakeSupplydrive([], status: 404);

    $this->artisan('supplydrive:status', ['--kvk' => '12345678'])
        ->expectsOutput('✓ Connection successful, no messages match the filters.')
        ->assertSuccessful();
});

it('refuses to run without a filter', function () {
    Http::fake();

    $this->artisan('supplydrive:status')
        ->expectsOutput('Give at least one filter: --functional-id, --message-id, --kvk, --gln or --vat.')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('prints the reason and fails when Supplydrive refuses the account', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    $this->artisan('supplydrive:status', ['--functional-id' => 'F260001'])
        ->expectsOutput('✗ Request failed: Failed to retrieve access_token from Supplydrive. HTTP status: 401. Response: {"error":"invalid_grant"}')
        ->assertFailed();
});

it('fails without a stack trace when the account is not configured', function () {
    config(['supplydrive.username' => null, 'supplydrive.password' => null]);
    app()->forgetInstance(SupplydriveClient::class);
    Http::fake();

    $this->artisan('supplydrive:status', ['--functional-id' => 'F260001'])
        ->expectsOutput('✗ Request failed: Supplydrive API config is incomplete (username, password).')
        ->assertFailed();
});
