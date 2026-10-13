<?php

use Darvis\SupplydriveApi\Mcp\SupplydriveServer;
use Darvis\SupplydriveApi\Mcp\Tools\ExplainStatuses;
use Darvis\SupplydriveApi\Mcp\Tools\LookupMessageStatus;
use Darvis\SupplydriveApi\SupplydriveApiServiceProvider;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Registrar;

it('registers the local server under the configured handle', function () {
    expect(app(Registrar::class)->getLocalServer('supplydrive'))->not->toBeNull();
});

it('leaves the server out when it is disabled', function () {
    // The handle is read when the registrar is resolved, so resolve it first: the registration
    // of the real boot then happened under 'supplydrive', and a second boot must add nothing.
    app(Registrar::class);
    config(['supplydrive.mcp.enabled' => false, 'supplydrive.mcp.handle' => 'other']);

    (new SupplydriveApiServiceProvider(app()))->boot();

    expect(app(Registrar::class)->getLocalServer('other'))->toBeNull()
        ->and(app(Registrar::class)->getLocalServer('supplydrive'))->not->toBeNull();
});

it('looks a message up by the invoice number through the tool', function () {
    app()->setLocale('en');
    $this->fakeSupplydrive([TestCase::message('Afgeleverd', 'F260001', 42)]);

    SupplydriveServer::tool(LookupMessageStatus::class, ['functional_id' => 'F260001'])
        ->assertOk()
        ->assertSee('1 message(s) for functioneelId F260001.')
        ->assertSee('Message 42, functional id F260001, interface 1000')
        ->assertSee('Status: Afgeleverd (delivered)')
        ->assertSee('The message has been delivered.');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/SD/Berichten/Status?functioneelId=F260001'));
});

it('maps every argument to its filter and drops the empty ones', function () {
    $this->fakeSupplydrive([]);

    SupplydriveServer::tool(LookupMessageStatus::class, [
        'message_id' => '12',
        'debtor_kvk' => '12345678',
        'debtor_gln' => '',
        'debtor_vat' => 'NL123456789B01',
    ])->assertOk();

    Http::assertSent(fn (Request $request) => str_ends_with(
        $request->url(),
        '/SD/Berichten/Status?berichtId=12&debiteurKVK=12345678&debiteurBTW=NL123456789B01'
    ));
});

it('names failed and pending messages as such', function () {
    app()->setLocale('en');
    $this->fakeSupplydrive([
        TestCase::message('Mapping gefaald', 'F260002', 1),
        TestCase::message('In verwerking', 'F260003', 2),
        ['id' => 3, 'status' => 'Something new'],
    ]);

    SupplydriveServer::tool(LookupMessageStatus::class, ['debtor_kvk' => '12345678'])
        ->assertOk()
        ->assertSee('3 message(s)')
        ->assertSee('Status: Mapping gefaald (failed, somebody has to act)')
        ->assertSee('Status: In verwerking (pending, ask again later)')
        ->assertSee("Message 3\nStatus: Something new (pending, ask again later)")
        ->assertSee('Unknown status.');
});

it('answers with text, not an error, when nothing matches', function () {
    $this->fakeSupplydrive([], status: 404);

    SupplydriveServer::tool(LookupMessageStatus::class, ['functional_id' => 'F269999'])
        ->assertOk()
        ->assertSee('No messages at Supplydrive match functioneelId F269999.');
});

it('refuses a call without a filter before anything is sent', function () {
    Http::fake();

    SupplydriveServer::tool(LookupMessageStatus::class, ['functional_id' => '  '])
        ->assertHasErrors(['Give at least one filter']);

    Http::assertNothingSent();
});

it('returns a refused login as a tool error with the password redacted', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['error' => 'invalid_grant', 'hint' => TestCase::PASSWORD], 401),
    ]);

    SupplydriveServer::tool(LookupMessageStatus::class, ['functional_id' => 'F260001'])
        ->assertHasErrors(['Failed to retrieve access_token from Supplydrive. HTTP status: 401.'])
        ->assertHasErrors(['[redacted]'])
        ->assertDontSee(TestCase::PASSWORD);
});

it('explains every status with its outcome', function () {
    app()->setLocale('en');

    SupplydriveServer::tool(ExplainStatuses::class, [])
        ->assertOk()
        ->assertSee('Afgeleverd (delivered): The message has been delivered.')
        ->assertSee('Mapping gefaald (failed):')
        ->assertSee('Aflevering in retry (pending):')
        ->assertSee('treat it as pending');
});
