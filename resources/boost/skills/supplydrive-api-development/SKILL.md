---
name: supplydrive-api-development
description: Work with darvis/supplydrive-api. Use it to send a UBL invoice to Supplydrive (a Dutch PEPPOL access point) from a Laravel app and keep the message id, to look up what happened to it by invoice number, message id or debtor, to act on a status (delivered, failed, pending), to handle a refused send or call, a 401, a timeout or a cached token, and to test code that talks to Supplydrive without calling it.
---

# darvis/supplydrive-api development

## When to use this skill

Use this skill when code sends a document to Supplydrive or asks Supplydrive for the status of a message in an application that has `darvis/supplydrive-api` installed, when a send or a lookup throws, when `php artisan supplydrive:status` or `supplydrive:send` fails, when you have to decide what to do with a status, or when you write tests around such code.

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

## How a send runs

1. `Darvis\SupplydriveApi\SupplydriveSender` is a singleton. It reads the config once and does not throw without a send account; the first `send()` does, with status `0`, before anything is sent.
2. `send($ublXml, $functionalId)` refuses an empty document with `InvalidArgumentException`, then does one `POST` to `SUPPLYDRIVE_SEND_URL` with basic auth (`SUPPLYDRIVE_SEND_USERNAME`, `SUPPLYDRIVE_SEND_PASSWORD`), `Content-Type: application/xml`, `Accept: application/json` and the document as the body. `$functionalId` only goes into the package's two log lines.
3. A 2xx gives a `SendResult`: `httpStatus`, `messageId`, `body` (as it came), `data` (decoded when JSON), `hasMessageId()`. The id is read from a JSON object (`berichtId`, `messageId`, `id`, top level or one level down), a bare number or a numeric body. Supplydrive does not document the shape; an unknown shape gives `messageId` null and the raw `body`.
4. A 4xx or 5xx throws `SupplydriveException` (`Supplydrive send failed. HTTP status: 422. Response: ...`, password redacted). **Nothing is retried**, not even a 401: a POST is not idempotent and a document sent twice is delivered twice.
5. A timeout is a `ConnectionException`. Supplydrive may have received the document before the connection dropped: look the invoice number up with `byFunctionalId()` before anyone sends it again.

| Situation | Result | `$e->status()` |
| --- | --- | --- |
| 2xx with a message id in the body | `SendResult`, `messageId` set | |
| 2xx without a recognisable id | `SendResult`, `messageId` null, `body` has the answer | |
| 401 | `SupplydriveException`: the send account | `401` |
| 400, 415, 422 | `SupplydriveException`: the document, the body names the rule | that status |
| 5xx | `SupplydriveException`; the document may have been received | that status |
| No send account | `SupplydriveException`: `Supplydrive send config is incomplete (send.username, send.password).` | `0` |
| Empty document | `InvalidArgumentException`, nothing is sent | |
| Timeout, DNS, refused connection | `ConnectionException`; the document may have arrived | none |

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

### Send an invoice and keep the message id

```php
use Darvis\SupplydriveApi\SupplydriveSender;

$result = app(SupplydriveSender::class)->send($ublXml, $invoice->invoice_nr);

$invoice->update([
    'peppol_sent_at' => now(),
    'peppol_message_id' => $result->messageId, // null when the body holds none; still sent
]);
```

Do this from a queued job with `$tries = 1`, and let the job check `peppol_sent_at` first. On a `ConnectionException`, look the invoice number up before the job or a person sends again; on a `SupplydriveException`, log the message and stop, the same document will be refused again.

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
php artisan supplydrive:send storage/app/peppol/F260001.xml --functional-id=F260001   # sends for real, prints the raw response
php artisan supplydrive:status --functional-id=F260001
php artisan supplydrive:status --message-id=1234
php artisan supplydrive:status --kvk=12345678
```

Exit code 0 for an answer (also when nothing matches), 1 for a failure, 2 without a filter.

### Let an AI agent look it up

With `laravel/mcp` installed the package registers a local MCP server. Add `php artisan mcp:start supplydrive` to the agent (`.mcp.json` for Claude Code). The `lookup-message-status` tool takes `functional_id`, `message_id`, `debtor_kvk`, `debtor_gln` or `debtor_vat` and answers with every message, its status and `delivered`, `failed, somebody has to act` or `pending, ask again later`; `explain-statuses` lists the statuses. A refused call comes back as a tool error with the exception message. The server only reads.

### Test code that uses the client

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
Http::fake([
    'portal.supplydrive.com/realms/*' => Http::response(['access_token' => 'token', 'expires_in' => 300]),
    'portal.supplydrive.com/SD/*' => Http::response([
        ['id' => 1, 'functionalId' => 'F260001', 'status' => 'Afgeleverd', 'interfaceId' => 1000],
    ]),
    'rest.supplydrive.com/*' => Http::response(['berichtId' => 4711]),
]);
config([
    'supplydrive.username' => 'user', 'supplydrive.password' => 'secret',
    'supplydrive.send.username' => 'send', 'supplydrive.send.password' => 'secret',
]);
```

Around a send, assert `Http::assertSentCount(1)`: a repeated send is a double invoice. Fake `Http::response('<Ack/>')` to see your code cope with a result without a message id.

Fake a 404 for "no messages", a 401 for a refused account. Keep the cache on the `array` store in tests, or a token from one test is reused in the next.

## Don'ts

- Don't retry a send, and don't give the job that sends more than one try. Check `byFunctionalId()` after a timeout instead.
- Don't send from a web request, and don't send a document `validate()` of darvis/ubl-peppol rejects.
- Don't reuse the portal account for the send endpoint or the other way round; they are different accounts.
- Don't compare `$message->status` with Dutch strings; use `knownStatus()`, `isDelivered()`, `isFailed()` and `isPending()`.
- Don't poll from a web request; a status lookup belongs in a scheduled command or a queued job.
- Don't retry a 401 or a 5xx yourself in a loop.
- Don't read `config('supplydrive.…')`; use `Darvis\SupplydriveApi\Support\SupplydriveConfig`.
- Don't log the exception message of the token endpoint with the response body of a send; the package redacts its own secrets, not yours.
