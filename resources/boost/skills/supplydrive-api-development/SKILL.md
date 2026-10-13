---
name: supplydrive-api-development
description: Work with darvis/supplydrive-api. Use it to look up what happened to an invoice or another message sent through Supplydrive (a Dutch PEPPOL access point) from a Laravel app, by invoice number, message id or debtor, to act on a status (delivered, failed, pending), to handle a refused call, a 401, a timeout or a cached token, and to test code that talks to Supplydrive without calling it.
---

# darvis/supplydrive-api development

## When to use this skill

Use this skill when code asks Supplydrive for the status of a message in an application that has `darvis/supplydrive-api` installed, when a lookup throws or `php artisan supplydrive:status` fails, when you have to decide what to do with a status, or when you write tests around such code.

## How a call runs

1. `Darvis\SupplydriveApi\SupplydriveClient` is a singleton. It reads the config once, when it is first resolved, and does not throw without an account; the first call does.
2. `messageStatus()` first checks the filters: only `functioneelId`, `berichtId`, `debiteurKVK`, `debiteurGLN` and `debiteurBTW` are allowed, empty values are dropped, and at least one has to remain, or it throws `InvalidArgumentException` before anything is sent.
3. Before the call it looks for a token on the instance, then in Laravel's cache (encrypted with `APP_KEY`, key `supplydrive.token.<sha256 of token URL, client id and username>`). Without one, or within sixty seconds of its expiry, it posts `grant_type=password`, `client_id`, `username` and `password` as a form to the token URL. Without `expires_in` it assumes 300 seconds.
4. The call goes to `GET <base_url>/SD/Berichten/Status?<filters>` with `Authorization: Bearer <token>` and `Accept: application/json`.
5. A 401 on a token the client already had (memory or cache): it forgets the token, logs in again and repeats the request once.
6. A 404 returns `[]`. Another 4xx or 5xx throws. Anything else is decoded and every item becomes a `Message`. Every request has a timeout of 30 seconds, 10 to connect.

| Situation | Result | `$e->status()` |
| --- | --- | --- |
| 200 with a list | a list of `Message` | |
| 200 with one object | a list of one `Message` | |
| 200 with an empty body or something that is not JSON | `[]` | |
| 404 | `[]`: no messages match | |
| 401 on a token the client already had | new login, the call is repeated once; a second 401 throws as below | `401` |
| 401 on a token fetched for this call | not repeated, throws as below | `401` |
| other 4xx or 5xx (never retried) | `SupplydriveException`: `Supplydrive API call failed. HTTP status: <status>. Response: <body>` | that status |
| Token endpoint 4xx or 5xx (never retried) | `SupplydriveException`: `Failed to retrieve access_token from Supplydrive. HTTP status: <status>. Response: <body>` | that status |
| Token response without `access_token` | `SupplydriveException`: `Supplydrive token response does not contain access_token.` | the status of that response, `200` |
| No username or password | `SupplydriveException`: `Supplydrive API config is incomplete (username, password).` | `0` |
| No filter, or an unknown filter name | `InvalidArgumentException`, nothing is sent | |
| Timeout, DNS, refused connection | `Illuminate\Http\Client\ConnectionException`; NOT a `SupplydriveException` | none |
| Cache store down, unknown store, no `APP_KEY` | one `Log::warning()` (`Supplydrive token cache is not available, ...`), the token stays in memory, calls work | |
| Cached value that cannot be decrypted | treated as no token: removed, a new login, nothing throws | |

## The statuses

`Message::$status` is the Dutch string the API returns. `knownStatus()` maps it to `Darvis\SupplydriveApi\MessageStatus`:

| `MessageStatus` | API value | `isDelivered()` | `isFailed()` | `isPending()` |
| --- | --- | --- | --- | --- |
| `Received` | `Ontvangen door supplydrive` | | | yes |
| `InProgress` | `In verwerking` | | | yes |
| `DeliveryInRetry` | `Aflevering in retry` | | | yes |
| `Delivered` | `Afgeleverd` | yes | | |
| `DeliveredManually` | `Handmatig afgeleverd` | yes | | |
| `DeliveredToIntermediary` | `Afgeleverd bij tussenpartij` | yes | | |
| `Undeliverable` | `Bericht kon niet afgeleverd worden` | | yes | |
| `MappingFailed` | `Mapping gefaald` | | yes | |
| a value the package does not know | as returned; `knownStatus()` is null | | | yes |

`description()` on both gives the translated explanation (`supplydrive-api::status.<key>`, `en` and `nl`). Compare with the enum, never with the Dutch strings in your own code.

## Scenarios

### Did an invoice arrive?

```php
use Darvis\SupplydriveApi\SupplydriveClient;

$messages = app(SupplydriveClient::class)->byFunctionalId($invoice->invoice_nr);

// An invoice that was sent twice has two messages; the last one is the current one.
$message = end($messages) ?: null;

if ($message === null) {
    // Supplydrive has never seen this number: not sent, or sent with another reference.
} elseif ($message->isDelivered()) {
    $invoice->update(['peppol_delivered_at' => now()]);
} elseif ($message->isFailed()) {
    $invoice->addLog('error', 'Peppol: '.$message->description());
} else {
    // Still pending. Ask again later.
}
```

### Look up by the id Supplydrive gave you

The HTTP response of a send through Supplydrive carries the message id. Store it, and use `byMessageId()`: that lookup is exact where `byFunctionalId()` finds every message with that reference.

```php
$messages = $supplydrive->byMessageId($invoice->peppol_message_id);
```

### Everything of one debtor

`byDebtorKvk()`, `byDebtorGln()` and `byDebtorVat()` return the last hundred messages of that debtor. Combine a debtor with a reference to narrow it down:

```php
$messages = $supplydrive->messageStatus([
    'debiteurBTW' => $debtor->vat_number,
    'functioneelId' => $invoice->invoice_nr,
]);
```

### Handle a failure

```php
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Illuminate\Http\Client\ConnectionException;

try {
    $messages = $supplydrive->byFunctionalId($number);
} catch (ConnectionException $e) {
    // No answer within the timeout. Try again next run.
} catch (SupplydriveException $e) {
    match (true) {
        $e->status() === 0 => 'the account is not configured',
        $e->status() === 401 => 'the account has no access to these messages, or lacks the role',
        $e->status() >= 500 => 'Supplydrive is down; try again next run',
        default => 'read the message, the body is in it with the password redacted',
    };
}
```

### From the terminal

```bash
php artisan supplydrive:status --functional-id=F260001
php artisan supplydrive:status --message-id=1234
php artisan supplydrive:status --kvk=12345678
```

Exit code 0 for an answer (also when nothing matches), 1 for a failure, 2 without a filter.

### Test code that uses the client

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
Http::fake([
    'portal.supplydrive.com/realms/*' => Http::response(['access_token' => 'token', 'expires_in' => 300]),
    'portal.supplydrive.com/SD/*' => Http::response([
        ['id' => 1, 'functionalId' => 'F260001', 'status' => 'Afgeleverd', 'interfaceId' => 1000],
    ]),
]);
config(['supplydrive.username' => 'user', 'supplydrive.password' => 'secret']);
```

Fake a 404 for "no messages", a 401 for a refused account. Keep the cache on the `array` store in tests, or a token from one test is reused in the next.

## Don'ts

- Don't compare `$message->status` with Dutch strings; use `knownStatus()`, `isDelivered()`, `isFailed()` and `isPending()`.
- Don't poll from a web request; a status lookup belongs in a scheduled command or a queued job.
- Don't retry a 401 or a 5xx yourself in a loop.
- Don't read `config('supplydrive.…')`; use `Darvis\SupplydriveApi\Support\SupplydriveConfig`.
- Don't log the exception message of the token endpoint with the response body of a send; the package redacts its own secrets, not yours.
