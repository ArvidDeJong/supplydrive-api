<?php

use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SendResult;
use Darvis\SupplydriveApi\SupplydriveSender;
use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts the document with basic auth as XML and asks for JSON back', function () {
    $this->fakeSendEndpoint('4711');

    $result = app(SupplydriveSender::class)->send(TestCase::UBL, 'F260001');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(TestCase::SEND_USERNAME.':'.TestCase::SEND_PASSWORD))
        && $request->hasHeader('Content-Type', 'application/xml')
        && $request->hasHeader('Accept', 'application/json')
        && $request->body() === TestCase::UBL);

    expect($result)->toBeInstanceOf(SendResult::class)
        ->and($result->httpStatus)->toBe(200)
        ->and($result->messageId)->toBe('4711')
        ->and($result->hasMessageId())->toBeTrue()
        ->and($result->body)->toBe('4711');
});

it('posts to the configured URL', function () {
    config(['supplydrive.send.url' => 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/TEST']);
    app()->forgetInstance(SupplydriveSender::class);
    $this->fakeSendEndpoint();

    app(SupplydriveSender::class)->send(TestCase::UBL);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/TEST');
});

it('never puts the send password in the URL', function () {
    $this->fakeSendEndpoint();

    app(SupplydriveSender::class)->send(TestCase::UBL);

    foreach (Http::recorded() as [$request]) {
        expect($request->url())->not->toContain(TestCase::SEND_PASSWORD)
            ->and($request->url())->not->toContain(urlencode(TestCase::SEND_PASSWORD));
    }
});

it('reads the message id from the shapes Supplydrive may answer with', function (string|array $body, ?string $expected, ?array $data) {
    $this->fakeSendEndpoint($body, 201);

    $result = app(SupplydriveSender::class)->send(TestCase::UBL);

    expect($result->httpStatus)->toBe(201)
        ->and($result->messageId)->toBe($expected)
        ->and($result->data)->toBe($data);
})->with([
    'a JSON object with berichtId' => [['berichtId' => 4711], '4711', ['berichtId' => 4711]],
    'a JSON object with messageId as string' => [['messageId' => 'abc-123'], 'abc-123', ['messageId' => 'abc-123']],
    'a JSON object with id and more' => [['id' => 99, 'status' => 'Ontvangen'], '99', ['id' => 99, 'status' => 'Ontvangen']],
    'the id one level down' => [['bericht' => ['berichtId' => 12]], '12', ['bericht' => ['berichtId' => 12]]],
    'a body that is only a number' => ['  4711 ', '4711', null],
    'a JSON string with a number' => ['"4711"', '4711', null],
    'an XML acknowledgement' => ['<Ack>accepted</Ack>', null, null],
    'free text' => ['Invoice accepted', null, null],
    'an empty body' => ['', null, null],
    'JSON without an id' => [['status' => 'ok'], null, ['status' => 'ok']],
]);

it('keeps the raw body when no message id is found', function () {
    $this->fakeSendEndpoint('<Ack>accepted</Ack>');

    $result = app(SupplydriveSender::class)->send(TestCase::UBL);

    expect($result->hasMessageId())->toBeFalse()
        ->and($result->body)->toBe('<Ack>accepted</Ack>');
});

it('throws with the status and the body when Supplydrive refuses the document, with the password redacted', function () {
    $this->fakeSendEndpoint('Unauthorized for '.TestCase::SEND_PASSWORD, 401);

    try {
        app(SupplydriveSender::class)->send(TestCase::UBL);
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(401)
            ->and($e->getMessage())->toBe('Supplydrive send failed. HTTP status: 401. Response: Unauthorized for [redacted]');
    }

    // A POST is not idempotent: a refusal is never repeated.
    Http::assertSentCount(1);
});

it('throws with the JSON body of a refusal', function () {
    $this->fakeSendEndpoint(['error' => 'Schematron failed', 'rules' => ['BR-CO-15']], 422);

    expect(fn () => app(SupplydriveSender::class)->send(TestCase::UBL))
        ->toThrow(SupplydriveException::class, 'Supplydrive send failed. HTTP status: 422. Response: {"error":"Schematron failed","rules":["BR-CO-15"]}');
});

it('throws with status 0 without a send account, and sends nothing', function () {
    config(['supplydrive.send.username' => '', 'supplydrive.send.password' => '']);
    app()->forgetInstance(SupplydriveSender::class);
    Http::fake();

    try {
        app(SupplydriveSender::class)->send(TestCase::UBL);
        $this->fail('No exception thrown');
    } catch (SupplydriveException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getMessage())->toBe('Supplydrive send config is incomplete (send.username, send.password).');
    }

    Http::assertNothingSent();
});

it('refuses an empty document before anything is sent', function () {
    Http::fake();

    expect(fn () => app(SupplydriveSender::class)->send("  \n"))
        ->toThrow(InvalidArgumentException::class, 'The UBL document to send is empty.');

    Http::assertNothingSent();
});

it('lets a connection error through untouched', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => app(SupplydriveSender::class)->send(TestCase::UBL))
        ->toThrow(ConnectionException::class, 'cURL error 28');
});

it('builds a result from a response', function () {
    $result = SendResult::fromResponse(200, '{"berichtId": "77"}');

    expect($result->messageId)->toBe('77')
        ->and($result->data)->toBe(['berichtId' => '77'])
        ->and(SendResult::extractMessageId(['berichtId' => '']))->toBeNull()
        ->and(SendResult::extractMessageId(4711))->toBe('4711')
        ->and(SendResult::extractMessageId(null))->toBeNull();
});
