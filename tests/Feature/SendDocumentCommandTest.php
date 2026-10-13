<?php

use Darvis\SupplydriveApi\Tests\TestCase;
use Illuminate\Support\Facades\Http;

function ublFile(): string
{
    $path = sys_get_temp_dir().'/supplydrive-api-test-'.uniqid().'.xml';
    file_put_contents($path, TestCase::UBL);

    return $path;
}

it('sends the file and prints the message id and the raw response', function () {
    $this->fakeSendEndpoint(['berichtId' => 4711]);
    $file = ublFile();

    $this->artisan('supplydrive:send', ['file' => $file, '--functional-id' => 'F260001'])
        ->expectsOutputToContain('✓ Document accepted, HTTP status 200.')
        ->expectsOutputToContain('Message id: 4711')
        ->expectsOutputToContain('{"berichtId":4711}')
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->body() === TestCase::UBL);

    unlink($file);
});

it('says so when the response holds no message id', function () {
    $this->fakeSendEndpoint('<Ack/>');
    $file = ublFile();

    $this->artisan('supplydrive:send', ['file' => $file])
        ->expectsOutputToContain('Message id: (not found in the response)')
        ->expectsOutputToContain('<Ack/>')
        ->assertExitCode(0);

    unlink($file);
});

it('exits with 1 when Supplydrive refuses the document', function () {
    $this->fakeSendEndpoint('Unauthorized', 401);
    $file = ublFile();

    $this->artisan('supplydrive:send', ['file' => $file])
        ->expectsOutputToContain('✗ Send failed: Supplydrive send failed. HTTP status: 401. Response: Unauthorized')
        ->assertExitCode(1);

    unlink($file);
});

it('exits with 2 for a file that does not exist, and sends nothing', function () {
    Http::fake();

    $this->artisan('supplydrive:send', ['file' => '/nowhere/invoice.xml'])
        ->expectsOutputToContain('File not found or not readable: /nowhere/invoice.xml')
        ->assertExitCode(2);

    Http::assertNothingSent();
});
