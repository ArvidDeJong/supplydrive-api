---
title: "Quick start"
nav_order: 3
description: "One complete example with darvis/supplydrive-api: a scheduled command that asks Supplydrive about every sent invoice and records delivered, failed or pending."
---

# Quick start

This page assumes the package is [installed](installation.md) and `php artisan supplydrive:status` works.

## The shortest version

```php
use Darvis\SupplydriveApi\SupplydriveClient;

$messages = app(SupplydriveClient::class)->byFunctionalId('F260001');

foreach ($messages as $message) {
    echo $message->status;          // "Afgeleverd"
    echo $message->description();   // "The message has been delivered."
    $message->isDelivered();        // true
}
```

`byFunctionalId()` looks up by the reference you gave the message, which for an invoice is the invoice number. The result is a list of `Darvis\SupplydriveApi\Message`: empty when Supplydrive has never seen that number, and more than one when you sent the same number more than once.

## Send first

The invoice has to reach Supplydrive before there is a status. Send it with the sender and keep the message id, the exact key for its history:

```php
use Darvis\SupplydriveApi\SupplydriveSender;

$result = app(SupplydriveSender::class)->send($ublXml, $invoice->invoice_nr);

$invoice->update(['peppol_sent_at' => now(), 'peppol_message_id' => $result->messageId]);
```

A refused document throws, a timeout may still mean it arrived; the [Sending](sending.md) page has the whole story.

## A complete example: record the delivery

An application that sends invoices through Supplydrive wants to know, later, whether they arrived. A scheduled command that runs a few times a day is the right place: the lookup is one HTTP call per invoice, and a 5xx or a timeout can wait for the next run.

```php
<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

class CheckPeppolDeliveries extends Command
{
    protected $signature = 'peppol:check-deliveries';

    protected $description = 'Ask Supplydrive what happened to the invoices that were sent and not yet delivered';

    public function handle(SupplydriveClient $supplydrive): int
    {
        $invoices = Invoice::whereNotNull('peppol_sent_at')
            ->whereNull('peppol_delivered_at')
            ->get();

        foreach ($invoices as $invoice) {
            try {
                $messages = $supplydrive->byFunctionalId($invoice->invoice_nr);
            } catch (ConnectionException $e) {
                // No answer within the timeout. Stop here; the next run tries again.
                $this->error('Supplydrive did not answer: '.$e->getMessage());

                return self::FAILURE;
            } catch (SupplydriveException $e) {
                // Refused: a wrong account (0, 401) or Supplydrive is down (5xx). The message
                // has the response body with the password redacted.
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            // The same invoice may have been sent twice; the last message is the current one.
            $message = end($messages) ?: null;

            if ($message === null) {
                // Supplydrive has never seen this number. Sent with another reference, or not at all.
                continue;
            }

            if ($message->isDelivered()) {
                $invoice->update(['peppol_delivered_at' => now()]);
            } elseif ($message->isFailed()) {
                $invoice->addLog('error', 'Peppol: '.$message->description());
            }

            // Pending: ask again next run.
        }

        return self::SUCCESS;
    }
}
```

Schedule it in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('peppol:check-deliveries')->everyFourHours();
```

## Which lookup to use

| You have | Use | Finds |
| --- | --- | --- |
| Your own reference, the invoice number | `byFunctionalId('F260001')` | every message sent with that reference |
| The id Supplydrive answered with when you sent the message | `byMessageId(1234)` | exactly that message |
| A debtor | `byDebtorKvk('12345678')`, `byDebtorGln('8712345678906')`, `byDebtorVat('NL123456789B01')` | the last hundred messages of that debtor |
| Several of the above | `messageStatus(['debiteurBTW' => 'NL123456789B01', 'functioneelId' => 'F260001'])` | the messages that match all of them |

The HTTP response of a send through Supplydrive carries the message id. Store it on the invoice when you have it; `byMessageId()` is exact where `byFunctionalId()` finds every message with that reference.

## What a status means

`$message->status` is the Dutch string the API returns. Don't compare it yourself; use the methods:

| Method | True for |
| --- | --- |
| `isDelivered()` | `Afgeleverd`, `Handmatig afgeleverd`, `Afgeleverd bij tussenpartij` |
| `isFailed()` | `Bericht kon niet afgeleverd worden`, `Mapping gefaald` |
| `isPending()` | `Ontvangen door supplydrive`, `In verwerking`, `Aflevering in retry`, and any status the package does not know |

`knownStatus()` gives the `Darvis\SupplydriveApi\MessageStatus` case, or null for a status the package does not know. `description()` explains the status in the locale of the application; English and Dutch ship with the package.

## Next

- [Sending](sending.md): the send account, the message id and why nothing is retried.
- [How it works](concepts.md): the login, the token cache, the repeat after a 401 and the timeouts.
- [API reference](api-reference.md): every method and what it returns.
- [Testing](testing.md): fake Supplydrive in the tests of the command above.
