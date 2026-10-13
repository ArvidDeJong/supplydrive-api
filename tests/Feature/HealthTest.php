<?php

use Darvis\SupplydriveApi\HealthResult;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\SupplydriveSender;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reports the portal healthy after a login and a 404 on a reference that does not exist', function () {
    $this->fakeSupplydrive([], status: 404);

    $result = app(SupplydriveClient::class)->healthCheck();

    expect($result)->toBeInstanceOf(HealthResult::class)
        ->and($result->endpoint)->toBe('portal')
        ->and($result->ok())->toBeTrue()
        ->and($result->reachable)->toBeTrue()
        ->and($result->authenticated)->toBeTrue()
        ->and($result->httpStatus)->toBe(200)
        ->and($result->durationMs)->toBeGreaterThanOrEqual(0);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'functioneelId=health-check-'));
});

it('reports the portal reachable but the account refused on a 401', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'bad '.TestCase::PASSWORD], 401),
    ]);

    $result = app(SupplydriveClient::class)->healthCheck();

    expect($result->ok())->toBeFalse()
        ->and($result->reachable)->toBeTrue()
        ->and($result->authenticated)->toBeFalse()
        ->and($result->httpStatus)->toBe(401)
        ->and($result->message)->toContain('[redacted]')
        ->and($result->message)->not->toContain(TestCase::PASSWORD);
});

it('reports the portal unreachable on a timeout, without throwing', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $result = app(SupplydriveClient::class)->healthCheck();

    expect($result->ok())->toBeFalse()
        ->and($result->reachable)->toBeFalse()
        ->and($result->authenticated)->toBeNull()
        ->and($result->httpStatus)->toBeNull()
        ->and($result->message)->toContain('did not answer');
});

it('reports the portal unknown on a 500, reachable but not refused', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response('down', 503),
    ]);

    $result = app(SupplydriveClient::class)->healthCheck();

    expect($result->ok())->toBeFalse()
        ->and($result->reachable)->toBeTrue()
        ->and($result->authenticated)->toBeNull()
        ->and($result->httpStatus)->toBe(503);
});

it('reports the portal config incomplete without sending anything', function () {
    config(['supplydrive.username' => '', 'supplydrive.password' => '']);
    app()->forgetInstance(SupplydriveClient::class);
    Http::fake();

    $result = app(SupplydriveClient::class)->healthCheck();

    expect($result->reachable)->toBeFalse()
        ->and($result->authenticated)->toBeNull()
        ->and($result->message)->toBe('Supplydrive API config is incomplete (username, password).');

    Http::assertNothingSent();
});

it('checks the send endpoint with a GET and basic auth, and sends no document', function (int $status) {
    $this->fakeSendEndpoint('Method Not Allowed', $status);

    $result = app(SupplydriveSender::class)->healthCheck();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(TestCase::SEND_USERNAME.':'.TestCase::SEND_PASSWORD))
        && $request->body() === '');

    expect($result->endpoint)->toBe('send')
        ->and($result->ok())->toBeTrue()
        ->and($result->httpStatus)->toBe($status);
})->with([200, 400, 404, 405]);

it('reports the send account refused on a 401 or 403', function (int $status) {
    $this->fakeSendEndpoint('', $status);

    $result = app(SupplydriveSender::class)->healthCheck();

    expect($result->ok())->toBeFalse()
        ->and($result->reachable)->toBeTrue()
        ->and($result->authenticated)->toBeFalse()
        ->and($result->httpStatus)->toBe($status);
})->with([401, 403]);

it('reports the send endpoint reachable but in error on a 5xx', function () {
    $this->fakeSendEndpoint('', 502);

    $result = app(SupplydriveSender::class)->healthCheck();

    expect($result->ok())->toBeFalse()
        ->and($result->reachable)->toBeTrue()
        ->and($result->authenticated)->toBeNull();
});

it('reports the send endpoint unreachable on a connection error, without throwing', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

    $result = app(SupplydriveSender::class)->healthCheck();

    expect($result->reachable)->toBeFalse()
        ->and($result->message)->toContain('did not answer');
});

it('reports the send config incomplete without sending anything', function () {
    config(['supplydrive.send.username' => '', 'supplydrive.send.password' => '']);
    app()->forgetInstance(SupplydriveSender::class);
    Http::fake();

    $result = app(SupplydriveSender::class)->healthCheck();

    expect($result->reachable)->toBeFalse()
        ->and($result->message)->toBe('Supplydrive send config is incomplete (send.username, send.password).');

    Http::assertNothingSent();
});

it('exits with 0 from supplydrive:health when both endpoints are healthy', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response([], 404),
        'rest.supplydrive.com/*' => Http::response('', 405),
    ]);

    $this->artisan('supplydrive:health')
        ->expectsOutputToContain('✓ Supplydrive is healthy.')
        ->assertExitCode(0);
});

it('exits with 1 from supplydrive:health when one endpoint refuses the account', function () {
    Http::fake([
        'portal.supplydrive.com/realms/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN, 'expires_in' => 300]),
        'portal.supplydrive.com/SD/*' => Http::response([], 404),
        'rest.supplydrive.com/*' => Http::response('', 401),
    ]);

    $this->artisan('supplydrive:health')
        ->expectsOutputToContain('refused')
        ->expectsOutputToContain('✗ Supplydrive is not healthy.')
        ->assertExitCode(1);
});
