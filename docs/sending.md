---
title: "Sending"
nav_order: 4
description: "Send a UBL invoice or credit note to Supplydrive with darvis/supplydrive-api: the send account, the message id you get back, and what a timeout means."
---

# Sending

Since 1.2.0 the package also sends: `Darvis\SupplydriveApi\SupplydriveSender` posts a UBL document to the send endpoint of Supplydrive, which delivers it over PEPPOL. The package does not build the document; [darvis/ubl-peppol](https://github.com/ArvidDeJong/ubl-peppol) does that, and any other UBL 2.1 builder works as well.

## The send account

The send endpoint is a REST URL with basic authentication. Supplydrive gives you the URL and the account when they set you up; the URL differs per environment (`LIVE`, `TEST`). It is **another account** than the portal login the status client uses, so both go in `.env`:

```env
SUPPLYDRIVE_SEND_URL=https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE
SUPPLYDRIVE_SEND_USERNAME=your-send-username
SUPPLYDRIVE_SEND_PASSWORD=your-send-password
```

The URL above is the default; set it when yours differs.

## Send a document

```php
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Http\Client\ConnectionException;

try {
    $result = app(SupplydriveSender::class)->send($ublXml, $invoice->invoice_nr);
} catch (ConnectionException $e) {
    // No answer within the timeout. Supplydrive may have received the document all the same:
    // look it up by the invoice number before you send it again.
    throw $e;
} catch (SupplydriveException $e) {
    // Refused: $e->status() is the HTTP status, 0 when the send account is not configured.
    // The message has the response body with the password redacted.
    throw $e;
}

$invoice->update([
    'peppol_sent_at' => now(),
    'peppol_message_id' => $result->messageId,
]);
```

`send()` returns a `Darvis\SupplydriveApi\SendResult` for a 2xx and throws for everything else. The second argument is your own reference; it only goes into the log lines the package writes, never to Supplydrive. The document itself carries the reference, as the invoice number in the UBL.

## The message id

Supplydrive answers a send with the id of the message. That id is the exact key for the status history of the document: `SupplydriveClient::byMessageId($result->messageId)` gives that one message, where `byFunctionalId()` gives every message with that invoice number, so also the earlier attempts. Store it on your invoice next to the moment of sending.

`SendResult` has:

| Property or method | What it is |
| --- | --- |
| `httpStatus` | The HTTP status of the response, a 2xx |
| `messageId` | The id Supplydrive gave the message, or null when none was found in the body |
| `hasMessageId()` | Whether there is one |
| `body` | The response body as Supplydrive sent it |
| `data` | The body decoded, when it is JSON; otherwise null |

Supplydrive does not document the shape of the response. The package reads the id from a JSON object (`berichtId`, `messageId` or `id`, at the top level or one level down), from a body that is only a number, or from a bare JSON number. A body that matches none of those gives a result without an id; the document was accepted all the same, and `body` has the answer for a look. `php artisan supplydrive:send` prints that body, so one real send shows you what Supplydrive answers.

## Nothing is retried

A POST is not idempotent: a document that is sent twice is delivered twice, and the receiver gets two invoices. So the sender never repeats a request, not for a 401, not for a 5xx and not after a timeout.

- A **4xx or 5xx** throws a `SupplydriveException` with the status; read it with `$e->status()`. A 401 is the send account, a 422 or 400 is the document; the message has the body.
- A **timeout** is an `Illuminate\Http\Client\ConnectionException`. Supplydrive may have received the document before the connection dropped. Look the invoice number up with `byFunctionalId()` before you send again, or send from a queued job that checks first.
- The **send account missing** throws before anything is sent, with status `0`: `Supplydrive send config is incomplete (send.username, send.password).`

Send from a queued job, not from a web request: the request takes as long as Supplydrive needs, and the job can look the status up afterwards.

## Is Supplydrive up?

`php artisan supplydrive:health` checks the portal and the send endpoint without sending a document, and `$sender->healthCheck()` does the same in code (it never throws). Run it from a scheduled command and alert on `ok()` being false, so you hear about a dead endpoint or a changed password before the first invoice of the day fails.

## From the terminal

```bash
php artisan supplydrive:send storage/app/peppol/F260001.xml --functional-id=F260001
```

It sends for real. The command prints the HTTP status, the message id and the response body as it came, and exits with `0` when the document was accepted, `1` when Supplydrive refused it or could not be reached, `2` when the file does not exist.

## Next

- [Quick start](quickstart.md): a scheduled command that records the delivery of every sent invoice.
- [Testing](testing.md): fake the send endpoint in your tests.
