# Supplydrive API for Laravel

[![Latest version](https://img.shields.io/packagist/v/darvis/supplydrive-api.svg)](https://packagist.org/packages/darvis/supplydrive-api)
[![Tests](https://github.com/ArvidDeJong/supplydrive-api/actions/workflows/tests.yml/badge.svg)](https://github.com/ArvidDeJong/supplydrive-api/actions/workflows/tests.yml)
[![PHP version](https://img.shields.io/packagist/dependency-v/darvis/supplydrive-api/php.svg)](https://packagist.org/packages/darvis/supplydrive-api)
[![License](https://img.shields.io/packagist/l/darvis/supplydrive-api.svg)](LICENSE)

A Laravel client for the Supplydrive API. [Supplydrive](https://www.supplydrive.cloud) is a Dutch PEPPOL access point and EDI provider: you send them an invoice, they deliver it. This package sends the invoice and asks their portal what happened to it: by your invoice number, by the message id they answered with, or per debtor.

It does not build the UBL document; [darvis/ubl-peppol](https://github.com/ArvidDeJong/ubl-peppol) does that.

## Features

- **Sending** - `SupplydriveSender::send($ublXml)` posts the document and gives you the message id Supplydrive answered with, the key for its status history. Nothing is retried: a document sent twice is delivered twice
- **Login handled** - the portal account is exchanged for an access token (OAuth password grant), kept encrypted in Laravel's cache until sixty seconds before it expires
- **One new token after a 401** - when the portal refuses a token the client already had, it logs in again and repeats the call once
- **Typed results** - a `Message` per item with `id`, `functionalId`, `status` and `interfaceId`, and a `MessageStatus` enum for the eight statuses Supplydrive documents, with `isDelivered()`, `isFailed()` and `isPending()`
- **Descriptions in English and Dutch** - `$message->description()` in the locale of your application
- **Failures with a status** - a `SupplydriveException` with the HTTP status in `$e->status()`; a 404 is simply an empty list
- **Safe with your password** - never in a URL, and replaced by `[redacted]` in error messages, log lines and command output
- **Two connection tests** - `php artisan supplydrive:status --functional-id=F260001` and `php artisan supplydrive:send invoice.xml`
- **An MCP server** - `php artisan mcp:start supplydrive` gives Claude and other AI agents the `lookup-message-status` and `explain-statuses` tools, registered when `laravel/mcp` is installed
- **Laravel Boost** - guideline and skill included, so an AI assistant in your app knows the API

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A Supplydrive portal account with the role for the status endpoint, and for sending the send URL and account; ask Supplydrive for them
- `laravel/mcp` 1.0 or higher for the MCP server (Laravel Boost installs it)

## Installation

```bash
composer require darvis/supplydrive-api
```

```env
SUPPLYDRIVE_USERNAME=your-username
SUPPLYDRIVE_PASSWORD=your-password

SUPPLYDRIVE_SEND_USERNAME=your-send-username
SUPPLYDRIVE_SEND_PASSWORD=your-send-password
```

```bash
php artisan supplydrive:status --functional-id=F260001
```

The command prints `✓ Connection successful` with a table of the messages, or `✗ Request failed:` with the reason.

## Quick start

```php
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Http\Client\ConnectionException;

try {
    // Send: a 2xx gives the message id, a refusal throws, nothing is retried.
    $result = app(SupplydriveSender::class)->send($ublXml, $invoice->invoice_nr);
    $invoice->update(['peppol_sent_at' => now(), 'peppol_message_id' => $result->messageId]);

    // Look up: by invoice number, by the message id, or per debtor.
    $supplydrive = app(SupplydriveClient::class);

    foreach ($supplydrive->byFunctionalId($invoice->invoice_nr) as $message) {
        $message->status;          // "Afgeleverd", as the API returns it
        $message->description();   // "The message has been delivered."
        $message->isDelivered();   // true
    }

    $supplydrive->byMessageId(1234);            // the id Supplydrive answered with when you sent it
    $supplydrive->byDebtorKvk('12345678');      // the last hundred messages of that debtor
    $supplydrive->messageStatus(['debiteurBTW' => 'NL123456789B01', 'functioneelId' => 'F260001']);
} catch (ConnectionException $e) {
    // Supplydrive could not be reached, or did not answer within the timeout. A send that timed
    // out may have arrived: look the invoice number up before you send it again.
} catch (SupplydriveException $e) {
    $status = $e->status();   // 401, 403, 500 ..., or 0 when there was no response
}
```

## Documentation

The full documentation lives on the [documentation site](https://arviddejong.github.io/supplydrive-api/):

- [Installation](https://arviddejong.github.io/supplydrive-api/installation.html): the steps, the settings and how to check that it works
- [Quick start](https://arviddejong.github.io/supplydrive-api/quickstart.html): one complete example, a job that records the delivery on the invoice
- [Sending](https://arviddejong.github.io/supplydrive-api/sending.html): the send account, the message id, and what a timeout means
- [How it works](https://arviddejong.github.io/supplydrive-api/concepts.html): the token, the repeat after a 401, the statuses, timeouts and where the password goes
- [API reference](https://arviddejong.github.io/supplydrive-api/api-reference.html): every public method, the enum, the exception and the commands
- [Testing](https://arviddejong.github.io/supplydrive-api/testing.html): fake Supplydrive in your tests
- [MCP server](https://arviddejong.github.io/supplydrive-api/mcp-server.html): the handle, the two tools and how to add it to your agent
- [Troubleshooting](https://arviddejong.github.io/supplydrive-api/troubleshooting.html): every message with its cause and its fix
- [FAQ](https://arviddejong.github.io/supplydrive-api/faq.html): short answers

## Laravel Boost

This package ships a guideline and a skill for [Laravel Boost](https://github.com/laravel/boost). Run `php artisan boost:install`, or `php artisan boost:update --discover` in a project that already uses Boost.

## Testing

```bash
composer test      # Pest
composer lint      # Pint, check only; composer format fixes
composer analyse   # Larastan
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Support the package

If darvis/supplydrive-api saves you time, buy me a beer 🍺: [sponsor me on GitHub](https://github.com/sponsors/ArvidDeJong).

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md).

## Security

Please report a vulnerability privately, as described in [SECURITY](SECURITY.md), not in the issue tracker.

## License

The MIT License (MIT). See [LICENSE](LICENSE).

This is an unofficial package, not made or endorsed by Supplydrive B.V.
