---
title: "API reference"
nav_order: 5
description: "Every public method of darvis/supplydrive-api: the client and its lookups, the Message object, the MessageStatus enum, the exception, the command, the config."
---

# API reference

## `Darvis\SupplydriveApi\SupplydriveClient`

Resolve it from the container: `app(SupplydriveClient::class)`, `app('supplydrive')`, or inject it. It is a singleton and is not final.

### Lookups

All of them return `list<Message>` and throw the same exceptions.

| Method | Query parameter | Finds |
| --- | --- | --- |
| `byFunctionalId(string $functionalId)` | `functioneelId` | every message with that reference, usually the invoice number |
| `byMessageId(int\|string $messageId)` | `berichtId` | the message with that id within Supplydrive |
| `byDebtorKvk(string $kvkNumber)` | `debiteurKVK` | the last hundred messages of that debtor |
| `byDebtorGln(string $gln)` | `debiteurGLN` | the last hundred messages of that debtor |
| `byDebtorVat(string $vatNumber)` | `debiteurBTW` | the last hundred messages of that debtor |
| `messageStatus(array $filters)` | any of the above | the messages that match every given filter |

`messageStatus()` takes the parameters in the spelling of the API; the allowed names are in `SupplydriveClient::FILTERS`. Values are trimmed, and empty ones are dropped.

**Throws**

- `InvalidArgumentException` when no filter has a value, or when a filter name is not one the API knows. Nothing is sent.
- `Darvis\SupplydriveApi\Exceptions\SupplydriveException` when Supplydrive refuses the login or the call, with the HTTP status in `status()`; `0` when the account is not configured. A 404 does not throw: it returns `[]`.
- `Illuminate\Http\Client\ConnectionException` on a timeout or a connection error.

### `forgetToken(): void`

Drops the access token in memory and in the cache. The next call logs in again.

### Constants

- `SupplydriveClient::STATUS_PATH`: `/SD/Berichten/Status`.
- `SupplydriveClient::FILTERS`: `['functioneelId', 'berichtId', 'debiteurKVK', 'debiteurGLN', 'debiteurBTW']`.

## `Darvis\SupplydriveApi\Message`

A readonly value object, one per item of the response.

| Property | Type | From the API |
| --- | --- | --- |
| `$id` | `int` | `id`, the id within Supplydrive |
| `$functionalId` | `?string` | `functionalId`, your reference |
| `$status` | `string` | `status`, the Dutch string as returned |
| `$interfaceId` | `?int` | `interfaceId` |
| `$raw` | `array` | the whole item, for fields the API adds later |

| Method | Returns |
| --- | --- |
| `Message::fromArray(array $item)` | a `Message` from one item of the response |
| `knownStatus()` | the `MessageStatus` case, or `null` for a status the package does not know |
| `description()` | the translated explanation of the status; `Unknown status.` for an unknown one |
| `isDelivered()` | `true` for `Delivered`, `DeliveredManually`, `DeliveredToIntermediary` |
| `isFailed()` | `true` for `Undeliverable`, `MappingFailed` |
| `isPending()` | `true` when neither of the above, an unknown status included |
| `toArray()` | `['id', 'functionalId', 'status', 'interfaceId', 'description']` |

## `Darvis\SupplydriveApi\MessageStatus`

A string backed enum; the values are the strings the API returns.

| Case | Value |
| --- | --- |
| `Received` | `Ontvangen door supplydrive` |
| `InProgress` | `In verwerking` |
| `DeliveryInRetry` | `Aflevering in retry` |
| `Delivered` | `Afgeleverd` |
| `DeliveredManually` | `Handmatig afgeleverd` |
| `Undeliverable` | `Bericht kon niet afgeleverd worden` |
| `MappingFailed` | `Mapping gefaald` |
| `DeliveredToIntermediary` | `Afgeleverd bij tussenpartij` |

| Method | Returns |
| --- | --- |
| `key()` | the translation key: `received`, `in_progress`, `delivery_in_retry`, `delivered`, `delivered_manually`, `undeliverable`, `mapping_failed`, `delivered_to_intermediary` |
| `description()` | `trans('supplydrive-api::status.<key>')` |
| `isDelivered()`, `isFailed()`, `isPending()` | as on `Message` |
| `MessageStatus::translate(string $key)` | one line of `status.php` by its key, `unknown` included; an empty string when the translator has none |

Use `MessageStatus::tryFrom($string)` for a string from elsewhere; `from()` throws on an unknown value.

## `Darvis\SupplydriveApi\Exceptions\SupplydriveException`

Extends `RuntimeException`. `status(): int` is the HTTP status of the response that caused it, the same as `getCode()`, and `0` when there was no response. The messages:

- `Supplydrive API config is incomplete (username, password).` (status 0)
- `Failed to retrieve access_token from Supplydrive. HTTP status: <status>. Response: <body>`
- `Supplydrive token response does not contain access_token.` (the status of that response)
- `Supplydrive API call failed. HTTP status: <status>. Response: <body>`

The password and the token are replaced by `[redacted]` in the body.

## `php artisan supplydrive:status`

| Option | Query parameter |
| --- | --- |
| `--functional-id=` | `functioneelId` |
| `--message-id=` | `berichtId` |
| `--kvk=` | `debiteurKVK` |
| `--gln=` | `debiteurGLN` |
| `--vat=` | `debiteurBTW` |

Prints the messages as a table with the description in the locale of the application. Exit code `0` for an answer, also when nothing matches; `1` when the login or the call fails, with `✗ Request failed:` and the message; `2` without a filter.

## Translations

Namespace `supplydrive-api`, file `status.php`, locales `en` and `nl`. Keys: the eight of `MessageStatus::key()` plus `unknown`. Publish with `--tag=supplydrive-api-lang`.

## Config

File `config/supplydrive.php`, publish tag `supplydrive-api-config`. Read it through `Darvis\SupplydriveApi\Support\SupplydriveConfig`:

| Accessor | Key | Environment variable | Default |
| --- | --- | --- | --- |
| `baseUrl()` | `base_url` | `SUPPLYDRIVE_BASE_URL` | `https://portal.supplydrive.com` |
| `clientId()` | `client_id` | `SUPPLYDRIVE_CLIENT_ID` | `SupplydrivePortal` |
| `connectTimeout()` | `connect_timeout` | `SUPPLYDRIVE_CONNECT_TIMEOUT` | `10` |
| `password()` | `password` | `SUPPLYDRIVE_PASSWORD` | |
| `timeout()` | `timeout` | `SUPPLYDRIVE_TIMEOUT` | `30` |
| `tokenCacheEnabled()` | `token_cache.enabled` | `SUPPLYDRIVE_TOKEN_CACHE` | `true` |
| `tokenCacheStore()` | `token_cache.store` | `SUPPLYDRIVE_TOKEN_CACHE_STORE` | the default store |
| `tokenUrl()` | `token_url` | `SUPPLYDRIVE_TOKEN_URL` | `https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token` |
| `username()` | `username` | `SUPPLYDRIVE_USERNAME` | |

## Container

- `Darvis\SupplydriveApi\SupplydriveClient`, singleton, alias `supplydrive`.
- No facade, no models, no routes.
