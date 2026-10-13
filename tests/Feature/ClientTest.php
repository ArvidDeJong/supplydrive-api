<?php

use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\Message;
use Darvis\SupplydriveApi\MessageStatus;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

function statusRequests(): Collection
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/SD/Berichten/Status'))
        ->map(fn (array $pair) => $pair[0]);
}

it('logs in with the password grant and sends the token as a bearer header', function () {
    $this->fakeSupplydrive([TestCase::message()]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token'
        && $request->method() === 'POST'
        && $request->isForm()
        && $request['grant_type'] === 'password'
        && $request['client_id'] === 'SupplydrivePortal'
        && $request['username'] === TestCase::USERNAME
        && $request['password'] === TestCase::PASSWORD);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://portal.supplydrive.com/SD/Berichten/Status?')
        && $request->hasHeader('Authorization', 'Bearer '.TestCase::ACCESS_TOKEN)
        && $request->hasHeader('Accept', 'application/json'));
});

it('never puts the password or the token in a URL', function () {
    $this->fakeSupplydrive([TestCase::message()]);

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    foreach (Http::recorded() as [$request]) {
        expect($request->url())->not->toContain(TestCase::PASSWORD)
            ->and($request->url())->not->toContain(urlencode(TestCase::PASSWORD))
            ->and($request->url())->not->toContain(TestCase::ACCESS_TOKEN);
    }
});

it('maps every convenience method to the query parameter of the API', function (string $method, string $argument, string $parameter) {
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->{$method}($argument);

    $request = statusRequests()->first();

    expect($request->url())->toBe('https://portal.supplydrive.com/SD/Berichten/Status?'.$parameter.'='.$argument);
})->with([
    ['byFunctionalId', 'F260001', 'functioneelId'],
    ['byMessageId', '12345', 'berichtId'],
    ['byDebtorKvk', '12345678', 'debiteurKVK'],
    ['byDebtorGln', '8712345678906', 'debiteurGLN'],
    ['byDebtorVat', 'NL123456789B01', 'debiteurBTW'],
]);

it('accepts a message id as an integer', function () {
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->byMessageId(12345);

    expect(statusRequests()->first()->url())->toEndWith('?berichtId=12345');
});

it('combines filters and drops the empty ones', function () {
    $this->fakeSupplydrive([]);

    app(SupplydriveClient::class)->messageStatus([
        'debiteurKVK' => '12345678',
        'functioneelId' => ' F260001 ',
        'berichtId' => '',
        'debiteurGLN' => null,
    ]);

    expect(statusRequests()->first()->url())
        ->toBe('https://portal.supplydrive.com/SD/Berichten/Status?debiteurKVK=12345678&functioneelId=F260001');
});

it('refuses a call without a filter before it logs in', function () {
    $this->fakeSupplydrive([]);

    expect(fn () => app(SupplydriveClient::class)->messageStatus([]))
        ->toThrow(InvalidArgumentException::class, 'At least one Supplydrive filter is required');

    expect(fn () => app(SupplydriveClient::class)->messageStatus(['functioneelId' => '']))
        ->toThrow(InvalidArgumentException::class, 'At least one Supplydrive filter is required');

    Http::assertNothingSent();
});

it('refuses a filter the API does not know', function () {
    $this->fakeSupplydrive([]);

    expect(fn () => app(SupplydriveClient::class)->messageStatus(['invoiceNumber' => 'F260001']))
        ->toThrow(InvalidArgumentException::class, "Unknown Supplydrive filter 'invoiceNumber'");

    Http::assertNothingSent();
});

it('turns the response into messages', function () {
    $this->fakeSupplydrive([
        TestCase::message('Afgeleverd', 'F260001', 1),
        TestCase::message('In verwerking', 'F260002', 2) + ['extra' => 'kept'],
    ]);

    $messages = app(SupplydriveClient::class)->byDebtorKvk('12345678');

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(Message::class)
        ->and($messages[0]->id)->toBe(1)
        ->and($messages[0]->functionalId)->toBe('F260001')
        ->and($messages[0]->status)->toBe('Afgeleverd')
        ->and($messages[0]->interfaceId)->toBe(1000)
        ->and($messages[0]->knownStatus())->toBe(MessageStatus::Delivered)
        ->and($messages[1]->knownStatus())->toBe(MessageStatus::InProgress)
        ->and($messages[1]->raw['extra'])->toBe('kept');
});

it('treats a 404 as no messages', function () {
    $this->fakeSupplydrive(['message' => 'not found'], status: 404);

    expect(app(SupplydriveClient::class)->byFunctionalId('F260001'))->toBe([]);
});

it('returns an empty list for a body that is not a list of messages', function (mixed $body) {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response($body, 200),
    ]);

    expect(app(SupplydriveClient::class)->byFunctionalId('F260001'))->toBe([]);
})->with([
    'empty body' => [''],
    'a string' => ['ok'],
    'a number' => ['42'],
]);

it('treats a single object as a list of one message', function () {
    $this->fakeSupplydrive(TestCase::message('Afgeleverd', 'F260001', 7));

    $messages = app(SupplydriveClient::class)->byMessageId(7);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->id)->toBe(7);
});

it('throws with the HTTP status and the body when Supplydrive refuses the call', function () {
    $this->fakeSupplydrive(['error' => 'forbidden'], status: 403);

    try {
        app(SupplydriveClient::class)->byFunctionalId('F260001');
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e)->toBeInstanceOf(RuntimeException::class)
            ->and($e->status())->toBe(403)
            ->and($e->getCode())->toBe(403)
            ->and($e->getMessage())->toBe('Supplydrive API call failed. HTTP status: 403. Response: {"error":"forbidden"}');
    }
});

it('throws when the token endpoint refuses the account, and redacts the password', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Invalid user credentials for '.TestCase::PASSWORD,
        ], 401),
    ]);

    try {
        app(SupplydriveClient::class)->byFunctionalId('F260001');
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(401)
            ->and($e->getMessage())->toStartWith('Failed to retrieve access_token from Supplydrive. HTTP status: 401.')
            ->and($e->getMessage())->toContain('[redacted]')
            ->and($e->getMessage())->not->toContain(TestCase::PASSWORD);
    }
});

it('redacts the token when Supplydrive echoes it in an error', function () {
    $this->fakeSupplydrive(['error' => 'bad token '.TestCase::ACCESS_TOKEN], status: 500);

    try {
        app(SupplydriveClient::class)->byFunctionalId('F260001');
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(500)
            ->and($e->getMessage())->toContain('[redacted]')
            ->and($e->getMessage())->not->toContain(TestCase::ACCESS_TOKEN);
    }
});

it('throws when the token response has no access_token', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['token_type' => 'Bearer'], 200),
    ]);

    expect(fn () => app(SupplydriveClient::class)->byFunctionalId('F260001'))
        ->toThrow(SupplydriveException::class, 'Supplydrive token response does not contain access_token.');
});

it('throws when the account is not configured, with status 0', function () {
    config(['supplydrive.username' => '', 'supplydrive.password' => '']);
    app()->forgetInstance(SupplydriveClient::class);
    Http::fake();

    try {
        app(SupplydriveClient::class)->byFunctionalId('F260001');
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getMessage())->toBe('Supplydrive API config is incomplete (username, password).');
    }

    Http::assertNothingSent();
});

it('passes the timeouts to every request, the token request included', function () {
    config(['supplydrive.timeout' => 7, 'supplydrive.connect_timeout' => 3]);
    app()->forgetInstance(SupplydriveClient::class);

    $seen = [];

    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

        return str_contains($request->url(), '/realms/')
            ? Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 300])
            : Http::response([]);
    });

    app(SupplydriveClient::class)->byFunctionalId('F260001');

    expect($seen)->toBe([[7.0, 3.0], [7.0, 3.0]]);
});
