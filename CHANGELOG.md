# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-10-13

### Added
- `SupplydriveClient::healthCheck()` and `SupplydriveSender::healthCheck()`, which never throw and
  return a `Darvis\SupplydriveApi\HealthResult` (`endpoint`, `reachable`, `authenticated`,
  `httpStatus`, `message`, `durationMs`, `ok()`). The portal check logs in and looks up a reference
  that does not exist (a 404 is healthy); the send check does one GET with basic auth and no
  document, so nothing reaches the PEPPOL network. A 401 or 403 means the account is refused, a
  timeout means unreachable, a 5xx means reachable with an unknown verdict on the account.
- `php artisan supplydrive:health`, which prints both checks as a table and exits with 0 when both
  are healthy, 1 otherwise, for a scheduler or a monitor.

## [1.2.0] - 2026-10-13

### Added
- `Darvis\SupplydriveApi\SupplydriveSender` (singleton, alias `supplydrive.sender`): `send(string $ublXml, ?string $functionalId = null)`
  posts a UBL document to the send endpoint of Supplydrive with basic authentication. A 2xx gives a
  `Darvis\SupplydriveApi\SendResult` with `httpStatus`, `messageId` (the id Supplydrive answered with,
  read from a JSON object, a bare number or a numeric body; null when the body holds none), `body`
  and `data`. A 4xx or 5xx throws `SupplydriveException` (`Supplydrive send failed. HTTP status: …`);
  a missing send account throws `Supplydrive send config is incomplete (send.username, send.password).`
  with status 0. Nothing is retried: a document sent twice is delivered twice.
- Config block `send` with `password`, `url` and `username` (`SUPPLYDRIVE_SEND_PASSWORD`,
  `SUPPLYDRIVE_SEND_URL`, `SUPPLYDRIVE_SEND_USERNAME`), read through `SupplydriveConfig::sendPassword()`,
  `sendUrl()` and `sendUsername()`. The URL defaults to the LIVE endpoint. The send account is another
  account than the portal login. Republish the config file if you published it.
- `php artisan supplydrive:send {file} {--functional-id=}`, which sends the document for real and prints
  the status, the message id and the response body as it came. Exit code 0, 1 on a refusal or a
  connection error, 2 when the file cannot be read.
- `Darvis\SupplydriveApi\Support\RedactsSecrets`, the trait both the client and the sender use to
  replace secrets in a message.

### Changed
- The package description and the docs: the package now sends as well as looks up.

## [1.1.0] - 2026-10-13

### Added
- A local MCP server (`Darvis\SupplydriveApi\Mcp\SupplydriveServer`, handle `supplydrive`,
  `SUPPLYDRIVE_MCP_ENABLED`, `SUPPLYDRIVE_MCP_HANDLE`) with the read only tools
  `lookup-message-status` and `explain-statuses`, registered when `laravel/mcp` is installed.

## [1.0.0] - 2026-10-13

### Added
- `Darvis\SupplydriveApi\SupplydriveClient`, a client for the status endpoint of the Supplydrive
  Portal API (`GET /SD/Berichten/Status`): `byFunctionalId()`, `byMessageId()`, `byDebtorKvk()`,
  `byDebtorGln()`, `byDebtorVat()` and `messageStatus(array $filters)` for any combination of the
  filters the API accepts. A 404 gives an empty list.
- Login with the OAuth password grant of the portal. The access token is kept in memory and,
  encrypted with `APP_KEY`, in Laravel's cache until sixty seconds before it expires
  (`SUPPLYDRIVE_TOKEN_CACHE`, `SUPPLYDRIVE_TOKEN_CACHE_STORE`). A 401 on a token the client
  already had makes it log in again and repeat the call once.
- `Message`, one item of the response with `id`, `functionalId`, `status`, `interfaceId` and the
  `raw` item, and `MessageStatus`, an enum of the eight statuses Supplydrive documents, with
  `isDelivered()`, `isFailed()`, `isPending()` and `description()`.
- Status descriptions in English and Dutch (`supplydrive-api::status.*`), publishable with the
  `supplydrive-api-lang` tag.
- `SupplydriveException` with the HTTP status in `status()`, `0` when there was no response. The
  password and the token are replaced by `[redacted]` in every message the package builds.
- `php artisan supplydrive:status` with `--functional-id`, `--message-id`, `--kvk`, `--gln` and
  `--vat`, which prints the messages as a table. Exit code 0 for an answer, 1 for a failure, 2
  without a filter.
- Config file `config/supplydrive.php` (`supplydrive-api-config` publish tag), read through
  `Darvis\SupplydriveApi\Support\SupplydriveConfig`.
- Laravel Boost guideline and skill.
