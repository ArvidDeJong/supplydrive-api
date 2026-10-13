# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The conventions shared by every darvis package (language, releases, CI, docs site, Boost guidelines, public API policy) are in [../CLAUDE.md](../CLAUDE.md); this file only holds what is specific to this package.

## Package overview

`darvis/supplydrive-api` is a Laravel package (PHP 8.2+, Laravel 11/12/13) with two clients for Supplydrive, a Dutch PEPPOL access point: `SupplydriveClient` for the status endpoint of the portal (what happened to a message you sent) and, since 1.2.0, `SupplydriveSender` for the send endpoint (post a UBL document). The two endpoints have different hosts, different authentication and different accounts.

- Namespace: `Darvis\SupplydriveApi\` → `src/`
- Service provider auto-registered via `extra.laravel.providers` in [composer.json](composer.json)
- Config key: `supplydrive` (file `config/supplydrive.php`, publish tag `supplydrive-api-config`)
- Container: `SupplydriveClient` (alias `supplydrive`) and `SupplydriveSender` (alias `supplydrive.sender`), both singletons. There is no facade and there are no models.
- MCP: [SupplydriveServer](src/Mcp/SupplydriveServer.php) with [LookupMessageStatus](src/Mcp/Tools/LookupMessageStatus.php) and [ExplainStatuses](src/Mcp/Tools/ExplainStatuses.php), registered as a local server under `supplydrive.mcp.handle`.
- Translations: `supplydrive-api::status.*`, `resources/lang/en` and `nl`, publish tag `supplydrive-api-lang`.

## Architecture

- [SupplydriveConfig](src/Support/SupplydriveConfig.php) is the only place that reads the package config; don't call `config('supplydrive.…')` elsewhere. `tests/Feature/ConfigAccessorTest.php` walks `src/` and `resources/` for it.
- [SupplydriveClient](src/SupplydriveClient.php): `messageStatus()` is the one method that talks to the status endpoint, `fetchAccessToken()` the one that talks to the token endpoint, `handleError()` the one place an exception for a response is built. `normaliseFilters()` is where a call is refused before anything is sent.
- The constructor does not throw. A missing account is reported by `fetchAccessToken()` on first use, so the client can be injected into a Livewire `boot()` or a controller constructor without a configured account, and `route:list` never fails on it.
- The token lives on the instance until sixty seconds before `expires_in` (300 seconds when the response has none; Keycloak tokens are short), and in Laravel's cache for that same period, encrypted with `Crypt`, under `supplydrive.token.<sha256 of token URL|client id|username>`. `tokenCache()` is the one method that touches the cache: it does nothing when the cache is off, and turns any failure of the store or of the encryption into one warning and a client that works from memory. There is no lock: the token endpoint is cheap and a status lookup is rare.
- `getAccessToken()` sets `$tokenIsFresh`: true when the token was fetched from the token endpoint during this call, false when it was held or came from the cache. `messageStatus()` repeats a call once after a 401 only when the token was not fresh.
- [SupplydriveSender](src/SupplydriveSender.php): `send()` is the one method that talks to the send endpoint, with basic auth, no token and no state. It never retries: a POST is not idempotent and a document sent twice is delivered twice. [SendResult](src/SendResult.php) holds the 2xx answer; `extractMessageId()` reads the likely shapes of a body nobody has documented, and returns null for the rest, so the raw `body` stays available. When one real body is seen, pin its shape there and in a test.
- Both classes build their exception messages through [RedactsSecrets](src/Support/RedactsSecrets.php): the client redacts the portal password and the token, the sender the send password.
- [Message](src/Message.php) is a readonly value object; [MessageStatus](src/MessageStatus.php) a string backed enum whose values are the Dutch strings of the API. A status the API adds later stays a plain string on `Message::$status`; `knownStatus()` is null and `isPending()` true for it.
- `laravel/mcp` is a dev dependency and a suggestion, not a requirement: it needs `illuminate/json-schema` 12.41 or higher, and a hard requirement would lock out Laravel 11 hosts. The provider registers the server only when the `Registrar` class exists. The MCP tool calls `SupplydriveClient::messageStatus()` and adds nothing but wording; keep every rule in the client, so the command, the tool and PHP stay in step. The tool returns a `SupplydriveException` as `Response::error()` with the same message, so an agent can correct itself; a `ConnectionException` too, prefixed. Don't catch other exceptions there.
- [MessageStatusCommand](src/Console/Commands/MessageStatusCommand.php) resolves the client inside its `try`, so a failure prints `✗ Request failed:` instead of a stack trace. Exit codes: 0 for an answer (also when nothing matches), 1 for a failure, 2 without a filter.

## Conventions

- Never put the password or the token in a URL. The account goes in the form body of the token request, the token in the `Authorization` header. The tests assert on the URL of every request.
- Never build an exception message from a response without `redactSecrets()`. The message contains the response body, a server can echo what it received, and the message is what the command prints.
- Never put the token in the cache unencrypted, and never put the username in the cache key: cache keys show up in `redis-cli keys` and in debug bars.
- Never let a cache failure fail a call. The cache only saves a login.
- Never retry a send, whatever the status. The client of the status endpoint may repeat a GET once; the sender never repeats a POST.
- Never retry more than once, and never anything but a 401 on a token that was not fresh. A 401 on a fresh token means the account has no access to those messages, or lacks the role; a loop there hammers the token endpoint with a login.
- Never throw a plain `RuntimeException`; throw [SupplydriveException](src/Exceptions/SupplydriveException.php) with the HTTP status as its code, `0` when there was no response. A call without a usable filter is a programming error and throws `InvalidArgumentException` before anything is sent.
- Never wrap or replace `Illuminate\Http\Client\ConnectionException` without a major. Host apps catch that class for timeouts.
- Never call Supplydrive from a test: `TestCase` calls `Http::preventStrayRequests()`; use `fakeSupplydrive()` or your own `Http::fake()`. Don't put a real account, customer name or invoice number in tests, docs or examples.
- The filter names (`functioneelId`, `berichtId`, `debiteurKVK`, `debiteurGLN`, `debiteurBTW`) and the status values are Dutch because the API is. The method names around them are English (`byFunctionalId()`, `byDebtorKvk()`); keep both as they are.
- Keep the public API compatible within 1.x: the list is in `CONTRIBUTING.md`.
