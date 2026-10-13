## darvis/supplydrive-api

A client for the status endpoint of the Supplydrive Portal API. Supplydrive is a Dutch PEPPOL access point: you send an invoice through them, and this package asks what happened to it. It logs in with a portal account and sends every call with the access token. It does not send invoices.

- Resolve `Darvis\SupplydriveApi\SupplydriveClient` from the container (a singleton, alias `supplydrive`). There is no facade. Don't call the portal with `Http::` yourself and don't fetch a token yourself.
- Lookups: `byFunctionalId($invoiceNumber)` (your reference, `functioneelId`), `byMessageId($id)` (the number Supplydrive answered with when the message was sent, `berichtId`), `byDebtorKvk()`, `byDebtorGln()`, `byDebtorVat()` (the last hundred messages of that debtor), and `messageStatus(array $filters)` for any combination of `functioneelId`, `berichtId`, `debiteurKVK`, `debiteurGLN` and `debiteurBTW`. All return a list of `Darvis\SupplydriveApi\Message`; a 404 is an empty list, not an exception.
- A call without a usable filter, or with a filter name the API does not know, throws `InvalidArgumentException` before anything is sent. Empty filter values are dropped.
- `Message` has `id`, `functionalId`, `status` (the Dutch string as the API returns it), `interfaceId` and `raw`. `knownStatus()` gives the `Darvis\SupplydriveApi\MessageStatus` case or null for a status the package does not know. `description()` is translated (`en`, `nl`); `isDelivered()`, `isFailed()`, `isPending()` sort the statuses: delivered is `Afgeleverd`, `Handmatig afgeleverd`, `Afgeleverd bij tussenpartij`; failed is `Bericht kon niet afgeleverd worden`, `Mapping gefaald`; everything else, an unknown status included, is pending. Compare with the enum, never with the Dutch strings.
- **A failed call throws** `Darvis\SupplydriveApi\Exceptions\SupplydriveException`, which extends `RuntimeException` (`Supplydrive API call failed. HTTP status: 403. Response: ...`). Read the HTTP status with `$e->status()` (the same as `$e->getCode()`), never by parsing the message; it is `0` when there was no response. A timeout or connection error is an `Illuminate\Http\Client\ConnectionException`, which is NOT a `SupplydriveException`: catch both.
- A 401 on a token the client already had: it logs in again and repeats the call **once**. A 401 that reaches your code means the account has no access to those messages or lacks the role for the endpoint; don't retry it. A 5xx is not retried either; poll from a scheduled command or a queued job, not from a web request.
- The access token is kept in memory and, encrypted with `APP_KEY`, in Laravel's cache until sixty seconds before it expires. `SUPPLYDRIVE_TOKEN_CACHE=false` turns the cache off, `SUPPLYDRIVE_TOKEN_CACHE_STORE` picks the store. `$client->forgetToken()` drops the token on purpose. Never cache or store the token yourself.
- Every request, the token request included, gives up after 30 seconds and 10 seconds to connect: `SUPPLYDRIVE_TIMEOUT` and `SUPPLYDRIVE_CONNECT_TIMEOUT`.
- The client can be built without an account; the first call then throws `Supplydrive API config is incomplete (username, password).` with status `0`. Set `SUPPLYDRIVE_USERNAME` and `SUPPLYDRIVE_PASSWORD`.
- `php artisan supplydrive:status --functional-id=F260001` (or `--message-id`, `--kvk`, `--gln`, `--vat`) prints the messages as a table and exits with 0, 1 on a failure, 2 without a filter. It is the connection test.
- Never put the password or the token in a URL, a log line or output. The package replaces them with `[redacted]` in its own exception messages; the rest of a response body can still name customers and invoices.
- Read settings through `Darvis\SupplydriveApi\Support\SupplydriveConfig` (`baseUrl()`, `clientId()`, `connectTimeout()`, `password()`, `timeout()`, `tokenCacheEnabled()`, `tokenCacheStore()`, `tokenUrl()`, `username()`), not with `config()`.
- In tests, never call Supplydrive: `Http::preventStrayRequests()`, fake `portal.supplydrive.com/realms/*` (return an `access_token`) and `portal.supplydrive.com/SD/*` (return a list of items with `id`, `functionalId`, `status`, `interfaceId`), and set `supplydrive.username` and `supplydrive.password` to any value. Keep the test cache on the `array` store, or a cached token travels from one test to the next.

@verbatim
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
