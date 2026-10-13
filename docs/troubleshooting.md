---
title: "Troubleshooting"
nav_order: 9
description: "Every error message and log line of darvis/supplydrive-api, quoted literally, with cause and fix: a missing account, a refused login, 401, timeouts, the cache."
---

# Troubleshooting

Start with `php artisan supplydrive:status --functional-id=<an invoice number>`. It makes one lookup with your account and prints the same message your code would get. For a send, `php artisan supplydrive:send <file>` does the same, for real. `php artisan supplydrive:health` checks both endpoints without sending anything and says which one is unreachable or refuses its account.

In code, read the HTTP status from the exception with `$e->status()` instead of taking it out of the message. `0` means there was no response at all.

## Supplydrive API config is incomplete (username, password).

**Cause.** `SUPPLYDRIVE_USERNAME` or `SUPPLYDRIVE_PASSWORD` is empty. The client is built without checking, so this appears on the first call, not when the client is resolved. The usual reasons: the variables are missing from `.env`, or the config is cached with the old values.

**Fix.** Add the account to `.env`, then run `php artisan config:clear` (or `php artisan config:cache` again in production). The exception has status `0`.

## Failed to retrieve access_token from Supplydrive. HTTP status: 401

Also with `400` or another status. Keycloak answers `401` with `invalid_grant` for a wrong username or password, and `400` with `invalid_client` for a client id it does not know.

**Cause.** The token endpoint did not accept the account. `[redacted]` in the message is where the server echoed your password.

**Fix.** Compare `SUPPLYDRIVE_USERNAME` and `SUPPLYDRIVE_PASSWORD` with what Supplydrive gave you, and check `SUPPLYDRIVE_TOKEN_URL` and `SUPPLYDRIVE_CLIENT_ID` if you changed them. The account for sending invoices (basic authentication on the messages URL) is a different account and does not work here. This request is never repeated.

## Supplydrive send config is incomplete (send.username, send.password).

**Cause.** `SUPPLYDRIVE_SEND_USERNAME` or `SUPPLYDRIVE_SEND_PASSWORD` is empty. The sender is built without checking, so this appears on the first `send()`, with status `0`, and nothing is sent.

**Fix.** Add the send account Supplydrive gave you to `.env`, then clear or rebuild the config cache. The portal account (`SUPPLYDRIVE_USERNAME`) is another account and does not work here.

## Supplydrive send failed. HTTP status: 401

**Cause.** The send endpoint did not accept the basic authentication. The request is never repeated.

**Fix.** Compare `SUPPLYDRIVE_SEND_USERNAME` and `SUPPLYDRIVE_SEND_PASSWORD` with what Supplydrive gave you, and check `SUPPLYDRIVE_SEND_URL`: the `LIVE` and `TEST` endpoints have their own accounts.

## Supplydrive send failed. HTTP status: 400, 415 or 422

**Cause.** Supplydrive refused the document. The message has the response body, which usually names the rule or the element.

**Fix.** Validate the document before sending (darvis/ubl-peppol has `validate()`), and compare the body with the PEPPOL rule it names. A 415 means the request was not sent as `application/xml`; the package always does, so look for a proxy in between.

## Supplydrive send failed. HTTP status: 500

**Cause.** Supplydrive is down or broke on the document. Nothing is retried, because the document may have been received before the error.

**Fix.** Look the invoice number up with `php artisan supplydrive:status --functional-id=<number>` before you send again. When nothing matches, send again later from the queue.

## The send result has no message id

**Cause.** The document was accepted (a 2xx), but the package did not find a message id in the body. Supplydrive does not document the shape of the response, and the package reads the likely shapes.

**Fix.** Nothing is lost: the invoice was sent, and the lookup by invoice number still works. Run `php artisan supplydrive:send` once to see the body as it came, and open an issue with that body (without customer data), so the next version reads it.

## Supplydrive token response does not contain access_token.

**Cause.** The token URL answered with a 2xx status, but the body is not JSON with an `access_token`. Usually a login page behind a wrong URL.

**Fix.** Check `SUPPLYDRIVE_TOKEN_URL`. The default is `https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token`.

## Supplydrive API call failed. HTTP status: 401

**Cause.** The login worked (there is a token) but the status endpoint refused the call. Supplydrive documents three reasons: messages were found but they belong to a debtor that is not linked to your account; the token expired; the account lacks the role for this endpoint. When the token was one the client already had, the client has by now logged in again and repeated the call once, so an expired token is ruled out; this is the answer to the second attempt.

**Fix.** Ask Supplydrive to add the role for the status endpoint to the account, or to link the debtor to it. A new login does not help.

## Supplydrive API call failed. HTTP status: 500

Also `502`, `503`, `504`.

**Cause.** The portal is down or overloaded. The package does not retry.

**Fix.** Try again later. In a scheduled command, stop the run and let the next one try again; in a queued job, release it with a backoff.

## The result is an empty list

**Cause.** The endpoint answered `404`: no messages match the filters. That is not an error. The reference may differ from what was sent (a prefix, a space, another number), the message may have been sent under another account, or it was never sent.

**Fix.** Look the message up by the id Supplydrive answered with when it was sent (`byMessageId()`), or by the debtor (`byDebtorKvk()`, `byDebtorGln()`, `byDebtorVat()`) to see which references Supplydrive has.

## At least one Supplydrive filter is required

An `InvalidArgumentException`, thrown before anything is sent.

**Cause.** `messageStatus()` was called with no filters, or with filters whose values are all empty. The convenience methods trim their argument too, so `byFunctionalId('')` ends here.

**Fix.** Check the value before calling; an invoice without a number has nothing to look up.

## Unknown Supplydrive filter

An `InvalidArgumentException`, thrown before anything is sent.

**Cause.** A key of the filter array is not one of `functioneelId`, `berichtId`, `debiteurKVK`, `debiteurGLN`, `debiteurBTW`.

**Fix.** Use the spelling of the API, or the convenience methods.

## Illuminate\Http\Client\ConnectionException

**Cause.** Supplydrive did not answer within `SUPPLYDRIVE_TIMEOUT` (30 seconds), the connection was not made within `SUPPLYDRIVE_CONNECT_TIMEOUT` (10 seconds), or the host could not be resolved. This is not a `SupplydriveException`.

**Fix.** Catch both classes. A lookup can simply be repeated later; nothing was changed.

## Supplydrive token cache is not available, the token is kept in memory only

A `Log::warning()`, once per client instance. Calls keep working.

**Cause.** The cache store does not exist (`SUPPLYDRIVE_TOKEN_CACHE_STORE` names one that is not in `config/cache.php`), is down, or there is no `APP_KEY` to encrypt with.

**Fix.** Fix the store or the key, or set `SUPPLYDRIVE_TOKEN_CACHE=false` if you don't want the cache. Without it, every process logs in once.

## There are no commands defined in the "supplydrive" namespace.

**Cause.** The service provider is not registered: package discovery is off for this package, or the package is not installed in this environment.

**Fix.** Run `composer show darvis/supplydrive-api`, and check `extra.laravel.dont-discover` in your `composer.json`.

## Give at least one filter: --functional-id, --message-id, --kvk, --gln or --vat.

**Cause.** `php artisan supplydrive:status` was run without an option. Exit code 2.

**Fix.** Give one, for example `--functional-id=F260001`.

## ✓ Connection successful, no messages match the filters.

Not a failure: the account works and Supplydrive answered `404`. See "The result is an empty list" above.

## ✗ Request failed:

The command prints this, with exit code 1, for every exception above; the text after it is one of the messages on this page.

## Looking up messages at Supplydrive...

The first line the command prints. When nothing follows for thirty seconds, the portal is not reachable from this server; the next line is then the `ConnectionException`.
