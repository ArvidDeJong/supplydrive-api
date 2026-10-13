## darvis/supplydrive-api

A client for Supplydrive, a Dutch PEPPOL access point. `SupplydriveSender` posts a UBL invoice to their send endpoint (basic auth), `SupplydriveClient` asks their portal (OAuth token) what happened to it. The two endpoints have different hosts and DIFFERENT accounts. The package does not build UBL; darvis/ubl-peppol does.

- Resolve `Darvis\SupplydriveApi\SupplydriveClient` (alias `supplydrive`) and `Darvis\SupplydriveApi\SupplydriveSender` (alias `supplydrive.sender`) from the container; both are singletons. There is no facade. Don't call Supplydrive with `Http::` yourself and don't fetch a token yourself.
- **Sending**: `$sender->send($ublXml, $invoiceNumber)` returns a `Darvis\SupplydriveApi\SendResult` for a 2xx: `httpStatus`, `messageId` (the id Supplydrive answered with, or null when the body holds none), `body`, `data`. Store `messageId` on the invoice next to `peppol_sent_at`: it is the exact key for the status history (`byMessageId()`). A 4xx or 5xx throws `SupplydriveException` (`Supplydrive send failed. HTTP status: 422. ...`), a missing send account throws with status 0. **Nothing is retried**: a document sent twice is delivered twice. A timeout (`ConnectionException`) may still mean the document arrived: look the invoice number up with `byFunctionalId()` before sending again. Send from a queued job, never from a web request. The second argument only goes into the package's log lines.
- Lookups: `byFunctionalId($invoiceNumber)` (your reference, `functioneelId`), `byMessageId($id)` (the number Supplydrive answered with when the message was sent, `berichtId`), `byDebtorKvk()`, `byDebtorGln()`, `byDebtorVat()` (the last hundred messages of that debtor), and `messageStatus(array $filters)` for any combination of `functioneelId`, `berichtId`, `debiteurKVK`, `debiteurGLN` and `debiteurBTW`. All return a list of `Darvis\SupplydriveApi\Message`; a 404 is an empty list, not an exception.
- A call without a usable filter, or with a filter name the API does not know, throws `InvalidArgumentException` before anything is sent. Empty filter values are dropped.
- `Message` has `id`, `functionalId`, `status` (the Dutch string as the API returns it), `interfaceId` and `raw`. `knownStatus()` gives the `Darvis\SupplydriveApi\MessageStatus` case or null for a status the package does not know. `description()` is translated (`en`, `nl`); `isDelivered()`, `isFailed()`, `isPending()` sort the statuses: delivered is `Afgeleverd`, `Handmatig afgeleverd`, `Afgeleverd bij tussenpartij`; failed is `Bericht kon niet afgeleverd worden`, `Mapping gefaald`; everything else, an unknown status included, is pending. Compare with the enum, never with the Dutch strings.
- **A failed call throws** `Darvis\SupplydriveApi\Exceptions\SupplydriveException`, which extends `RuntimeException` (`Supplydrive API call failed. HTTP status: 403. Response: ...`). Read the HTTP status with `$e->status()` (the same as `$e->getCode()`), never by parsing the message; it is `0` when there was no response. A timeout or connection error is an `Illuminate\Http\Client\ConnectionException`, which is NOT a `SupplydriveException`: catch both.
- A 401 on a token the client already had: it logs in again and repeats the call **once**. A 401 that reaches your code means the account has no access to those messages or lacks the role for the endpoint; don't retry it. A 5xx is not retried either; poll from a scheduled command or a queued job, not from a web request.
- The access token is kept in memory and, encrypted with `APP_KEY`, in Laravel's cache until sixty seconds before it expires. `SUPPLYDRIVE_TOKEN_CACHE=false` turns the cache off, `SUPPLYDRIVE_TOKEN_CACHE_STORE` picks the store. `$client->forgetToken()` drops the token on purpose. Never cache or store the token yourself.
- Every request, the token request included, gives up after 30 seconds and 10 seconds to connect: `SUPPLYDRIVE_TIMEOUT` and `SUPPLYDRIVE_CONNECT_TIMEOUT`.
- The client can be built without an account; the first call then throws `Supplydrive API config is incomplete (username, password).` with status `0`. Set `SUPPLYDRIVE_USERNAME` and `SUPPLYDRIVE_PASSWORD`. The sender needs `SUPPLYDRIVE_SEND_USERNAME` and `SUPPLYDRIVE_SEND_PASSWORD` (another account), and `SUPPLYDRIVE_SEND_URL` when it differs from the LIVE default `https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE`. Never reuse one account for the other.
- `php artisan supplydrive:status --functional-id=F260001` (or `--message-id`, `--kvk`, `--gln`, `--vat`) prints the messages as a table and exits with 0, 1 on a failure, 2 without a filter. It is the connection test. `php artisan supplydrive:send invoice.xml --functional-id=F260001` sends a document FOR REAL and prints the status, the message id and the raw response body; exit 0, 1 on a refusal, 2 when the file cannot be read.
- With `laravel/mcp` installed, `php artisan mcp:start supplydrive` starts a local MCP server with the read only tools `lookup-message-status` (`functional_id`, `message_id`, `debtor_kvk`, `debtor_gln`, `debtor_vat`; at least one) and `explain-statuses`. `SUPPLYDRIVE_MCP_ENABLED=false` leaves it out. It sees every message of the account, so expose it only where the app's invoices may be seen.
- `$client->healthCheck()` and `$sender->healthCheck()` never throw and return a `HealthResult` (`reachable`, `authenticated`, `httpStatus`, `message`, `durationMs`, `ok()`). The portal check looks up a reference that does not exist, the send check does a GET without a document: nothing reaches PEPPOL. `php artisan supplydrive:health` runs both, exit 0 when healthy. Use these for a monitor or a "test connection" button; never send a document to test.
- Never put the password or the token in a URL, a log line or output. The package replaces them with `[redacted]` in its own exception messages; the rest of a response body can still name customers and invoices.
- Read settings through `Darvis\SupplydriveApi\Support\SupplydriveConfig` (`baseUrl()`, `clientId()`, `connectTimeout()`, `mcpEnabled()`, `mcpHandle()`, `password()`, `sendPassword()`, `sendUrl()`, `sendUsername()`, `timeout()`, `tokenCacheEnabled()`, `tokenCacheStore()`, `tokenUrl()`, `username()`), not with `config()`.
- In tests, never call Supplydrive: `Http::preventStrayRequests()`, fake `portal.supplydrive.com/realms/*` (return an `access_token`), `portal.supplydrive.com/SD/*` (return a list of items with `id`, `functionalId`, `status`, `interfaceId`) and `rest.supplydrive.com/*` (return `['berichtId' => 4711]`), and set `supplydrive.username`, `supplydrive.password`, `supplydrive.send.username` and `supplydrive.send.password` to any value. Assert `Http::assertSentCount(1)` around a send. Keep the test cache on the `array` store, or a cached token travels from one test to the next.

@verbatim
<code-snippet name="Send an invoice from a queued job and keep the message id" lang="php">
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Http\Client\ConnectionException;

public $tries = 1; // never let the queue repeat a send by itself

public function handle(SupplydriveSender $sender, SupplydriveClient $client): void
{
    if ($this->invoice->peppol_sent_at) {
        return;
    }

    try {
        $result = $sender->send($ublXml, $this->invoice->invoice_nr);
    } catch (ConnectionException $e) {
        // Timed out: Supplydrive may have the document. Check before anyone sends it again.
        if ($client->byFunctionalId($this->invoice->invoice_nr) !== []) {
            $this->invoice->update(['peppol_sent_at' => now()]);

            return;
        }

        throw $e;
    } catch (SupplydriveException $e) {
        // Refused: $e->status() is the HTTP status, the message has the body. Tell someone; don't retry.
        $this->invoice->addLog('error', 'Peppol: '.$e->getMessage());

        return;
    }

    // messageId can be null when the body holds none; the document was accepted all the same.
    $this->invoice->update(['peppol_sent_at' => now(), 'peppol_message_id' => $result->messageId]);
}
</code-snippet>

<code-snippet name="Record the delivery of an invoice from a scheduled command" lang="php">
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\SupplydriveClient;
use Illuminate\Http\Client\ConnectionException;

public function handle(SupplydriveClient $supplydrive): int
{
    foreach (Invoice::whereNotNull('peppol_sent_at')->whereNull('peppol_delivered_at')->get() as $invoice) {
        try {
            $messages = $supplydrive->byFunctionalId($invoice->invoice_nr);
        } catch (ConnectionException|SupplydriveException $e) {
            // Supplydrive is down, or refused the account: stop and try again next run.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // The same invoice can be sent more than once; the last message is the one that counts.
        $message = end($messages);

        if ($message && $message->isDelivered()) {
            $invoice->update(['peppol_delivered_at' => now()]);
        } elseif ($message && $message->isFailed()) {
            $invoice->addLog('error', 'Peppol: '.$message->description());
        }
    }

    return self::SUCCESS;
}
</code-snippet>
@endverbatim
