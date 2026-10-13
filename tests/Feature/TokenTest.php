<?php

use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function tokenRequestCount(): int
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/realms/'))
        ->count();
}

/**
 * What the next web request or the next process does: build a new client.
 */
function nextRequest(): SupplydriveClient
{
    app()->forgetInstance(SupplydriveClient::class);

    return app(SupplydriveClient::class);
}

it('keeps the token on the instance for the next call', function () {
    $this->fakeSupplydrive([]);

    $client = app(SupplydriveClient::class);
    $client->byFunctionalId('F260001');
    $client->byFunctionalId('F260002');

    expect(tokenRequestCount())->toBe(1);
});

it('keeps the token in the cache, so the next web request does not fetch its own', function () {
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');
    nextRequest()->byFunctionalId('F260002');
    nextRequest()->byDebtorKvk('12345678');

    expect(tokenRequestCount())->toBe(1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'debiteurKVK=12345678')
        && $request->hasHeader('Authorization', 'Bearer '.TestCase::ACCESS_TOKEN));
});

it('encrypts the cached token and keeps the account out of the cache key', function () {
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    $cached = Cache::get(TestCase::tokenCacheKey());

    expect($cached)->toBeString()
        ->and($cached)->not->toContain(TestCase::ACCESS_TOKEN)
        ->and(Crypt::decryptString($cached))->toContain(TestCase::ACCESS_TOKEN)
        ->and(TestCase::tokenCacheKey())->not->toContain(TestCase::USERNAME);
});

it('caches the token until sixty seconds before it expires', function () {
    $this->travelTo(now()->startOfSecond());
    $this->fakeSupplydrive([], expiresIn: 300);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    $this->travel(239)->seconds();
    nextRequest()->byFunctionalId('F260001');
    expect(tokenRequestCount())->toBe(1);

    $this->travel(2)->seconds();
    nextRequest()->byFunctionalId('F260001');
    expect(tokenRequestCount())->toBe(2);
});

it('assumes five minutes when the token response has no expires_in', function () {
    $this->travelTo(now()->startOfSecond());

    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN]),
        'portal.supplydrive.com/SD/*' => Http::response([]),
    ]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    $this->travel(239)->seconds();
    nextRequest()->byFunctionalId('F260001');
    expect(tokenRequestCount())->toBe(1);

    $this->travel(2)->seconds();
    nextRequest()->byFunctionalId('F260001');
    expect(tokenRequestCount())->toBe(2);
});

it('does not cache a token that lives sixty seconds or less', function () {
    $this->fakeSupplydrive([], expiresIn: 60);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    expect(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('ignores a cached value it cannot decrypt and fetches a new token', function () {
    Cache::put(TestCase::tokenCacheKey(), 'not-encrypted', 300);
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    expect(tokenRequestCount())->toBe(1)
        ->and(Crypt::decryptString(Cache::get(TestCase::tokenCacheKey())))->toContain(TestCase::ACCESS_TOKEN);
});

it('keeps the token in memory only when the cache is turned off', function () {
    config(['supplydrive.token_cache.enabled' => false]);
    $this->fakeSupplydrive([]);

    nextRequest()->byFunctionalId('F260001');
    nextRequest()->byFunctionalId('F260001');

    expect(tokenRequestCount())->toBe(2)
        ->and(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('uses the configured cache store', function () {
    config(['supplydrive.token_cache.store' => 'other']);
    config(['cache.stores.other' => ['driver' => 'array']]);
    $this->fakeSupplydrive([]);

    nextRequest()->byFunctionalId('F260001');

    expect(Cache::store('other')->has(TestCase::tokenCacheKey()))->toBeTrue()
        ->and(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();
});

it('works from memory and logs one warning when the cache store does not exist', function () {
    config(['supplydrive.token_cache.store' => 'missing']);
    $this->fakeSupplydrive([]);

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines) {
        $lines[] = [$event->level, $event->message];
    });

    $client = nextRequest();
    $client->byFunctionalId('F260001');
    $client->byFunctionalId('F260002');

    expect(tokenRequestCount())->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0][0])->toBe('warning')
        ->and($lines[0][1])->toStartWith('Supplydrive token cache is not available, the token is kept in memory only:')
        ->and($lines[0][1])->not->toContain(TestCase::ACCESS_TOKEN);
});

it('forgets the token on request, in memory and in the cache', function () {
    $this->fakeSupplydrive([]);

    $client = app(SupplydriveClient::class);
    $client->byFunctionalId('F260001');
    $client->forgetToken();

    expect(Cache::has(TestCase::tokenCacheKey()))->toBeFalse();

    $client->byFunctionalId('F260001');

    expect(tokenRequestCount())->toBe(2);
});

/**
 * A token endpoint that hands out token-1, token-2, ... and a status endpoint that only accepts
 * $accepted. $accepted is a reference, so a test can revoke a token halfway.
 */
function fakeRotatingTokens(?string &$accepted): void
{
    $issued = 0;

    Http::fake([
        'portal.supplydrive.com/realms/*' => function () use (&$issued) {
            return Http::response(['access_token' => 'token-'.(++$issued), 'expires_in' => 300]);
        },
        'portal.supplydrive.com/SD/*' => function (Request $request) use (&$accepted) {
            return $request->hasHeader('Authorization', 'Bearer '.$accepted)
                ? Http::response([TestCase::message()])
                : Http::response(['error' => 'Token expired'], 401);
        },
    ]);
}

/**
 * @return array<int, string>
 */
function requestTrail(): array
{
    return Http::recorded()->map(fn (array $pair) => str_contains($pair[0]->url(), '/realms/')
        ? 'token'
        : 'status '.($pair[0]->header('Authorization')[0] ?? ''))->all();
}

it('fetches a new token and repeats the call once when a held token is refused', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);

    $client = app(SupplydriveClient::class);
    $client->byFunctionalId('F260001');

    // The portal revokes token-1; from now on only token-2 works.
    $accepted = 'token-2';

    expect($client->byFunctionalId('F260001'))->toHaveCount(1);

    expect(requestTrail())->toBe([
        'token',
        'status Bearer token-1',
        'status Bearer token-1',
        'token',
        'status Bearer token-2',
    ]);
});

it('does the same for a token that came from the cache, and replaces the cached one', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);
    app(SupplydriveClient::class)->byFunctionalId('F260001');

    $accepted = 'token-2';

    expect(nextRequest()->byFunctionalId('F260001'))->toHaveCount(1);

    expect(requestTrail())->toBe(['token', 'status Bearer token-1', 'status Bearer token-1', 'token', 'status Bearer token-2']);

    nextRequest()->byFunctionalId('F260001');

    expect(array_slice(requestTrail(), 5))->toBe(['status Bearer token-2']);
});

it('does not repeat the call after a 401 on a token it just fetched', function () {
    $this->fakeSupplydrive(['error' => 'The user does not have the required role'], status: 401);

    try {
        app(SupplydriveClient::class)->byFunctionalId('F260001');
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(401)
            ->and($e->getMessage())->toBe('Supplydrive API call failed. HTTP status: 401. Response: {"error":"The user does not have the required role"}');
    }

    expect(requestTrail())->toBe(['token', 'status Bearer '.TestCase::ACCESS_TOKEN]);
});

it('throws after a second 401 and does not retry a third time', function () {
    $accepted = 'token-1';
    fakeRotatingTokens($accepted);
    app(SupplydriveClient::class)->byFunctionalId('F260001');

    // Nothing is accepted any more: the account lost its access.
    $accepted = null;

    expect(fn () => nextRequest()->byFunctionalId('F260001'))
        ->toThrow(SupplydriveException::class, 'Supplydrive API call failed. HTTP status: 401.');

    expect(requestTrail())->toBe(['token', 'status Bearer token-1', 'status Bearer token-1', 'token', 'status Bearer token-2']);
});
