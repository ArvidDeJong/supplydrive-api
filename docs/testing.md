---
title: "Testing"
nav_order: 7
description: "Test code that uses darvis/supplydrive-api without calling Supplydrive: fake the token, status and send endpoints with Http::fake() and assert what was sent."
---

# Testing

The client sends every request with Laravel's HTTP client, so `Http::fake()` sees them all. Never let a test reach Supplydrive: call `Http::preventStrayRequests()`, and a forgotten fake fails the test instead of logging in with a real account.

## The two endpoints

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    // The login. Any access_token will do; expires_in decides how long it is cached.
    'portal.supplydrive.com/realms/*' => Http::response([
        'access_token' => 'test-token',
        'token_type' => 'Bearer',
        'expires_in' => 300,
    ]),

    // The status endpoint: a list of items as Supplydrive returns them.
    'portal.supplydrive.com/SD/*' => Http::response([
        ['id' => 1, 'functionalId' => 'F260001', 'status' => 'Afgeleverd', 'interfaceId' => 1000],
    ]),
]);
```

The client needs an account, or the first call throws before anything is sent:

```php
config(['supplydrive.username' => 'test', 'supplydrive.password' => 'secret']);
```

Keep the test cache on the `array` store (the default of a Laravel test run), or a token cached by one test is reused by the next.

## The send endpoint

```php
Http::fake([
    'rest.supplydrive.com/*' => Http::response(['berichtId' => 4711]),
]);

config(['supplydrive.send.username' => 'test', 'supplydrive.send.password' => 'secret']);
```

Fake a 401 to see your code handle a refused account, a 422 for a refused document, and a body without an id (`Http::response('<Ack/>')`) to see that your code stores nothing but still marks the invoice as sent. For a timeout, throw from the fake: `Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'))`. Assert that exactly one request was sent (`Http::assertSentCount(1)`); a send that is repeated delivers the invoice twice.

## A complete test

For the `peppol:check-deliveries` command of the [quick start](quickstart.md):

```php
<?php

use App\Models\Invoice;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['supplydrive.username' => 'test', 'supplydrive.password' => 'secret']);
});

it('records the delivery of an invoice Supplydrive delivered', function () {
    $invoice = Invoice::factory()->create(['invoice_nr' => 'F260001', 'peppol_sent_at' => now()]);

    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response([
            ['id' => 1, 'functionalId' => 'F260001', 'status' => 'Afgeleverd', 'interfaceId' => 1000],
        ]),
    ]);

    $this->artisan('peppol:check-deliveries')->assertSuccessful();

    expect($invoice->fresh()->peppol_delivered_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/SD/Berichten/Status?functioneelId=F260001')
        && $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('leaves an invoice alone that Supplydrive does not know', function () {
    $invoice = Invoice::factory()->create(['invoice_nr' => 'F260002', 'peppol_sent_at' => now()]);

    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response(['message' => 'not found'], 404),
    ]);

    $this->artisan('peppol:check-deliveries')->assertSuccessful();

    expect($invoice->fresh()->peppol_delivered_at)->toBeNull();
});

it('stops when Supplydrive refuses the account', function () {
    Invoice::factory()->create(['invoice_nr' => 'F260003', 'peppol_sent_at' => now()]);

    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    $this->artisan('peppol:check-deliveries')->assertFailed();
});
```

## Faking the client instead

When the code under test only needs messages, skip the HTTP layer and bind a fake client:

```php
use Darvis\SupplydriveApi\Message;
use Darvis\SupplydriveApi\SupplydriveClient;

$this->mock(SupplydriveClient::class, function ($mock) {
    $mock->shouldReceive('byFunctionalId')
        ->with('F260001')
        ->andReturn([Message::fromArray(['id' => 1, 'functionalId' => 'F260001', 'status' => 'Afgeleverd', 'interfaceId' => 1000])]);
});
```

`Message::fromArray()` takes the same array the API returns, so a fixture reads like a response.

## Things worth a test of their own

- A 401 for a status lookup is thrown after one repeat; fake the status endpoint with a sequence (`Http::sequence()`) to see the repeat.
- An unknown status is pending: `['status' => 'Something new']` must not mark an invoice as delivered or failed.
- A timeout is an `Illuminate\Http\Client\ConnectionException`: `Http::fake(fn () => throw new ConnectionException('timed out'))`.
