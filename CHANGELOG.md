# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
