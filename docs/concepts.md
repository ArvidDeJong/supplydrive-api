---
title: "How it works"
nav_order: 4
description: "How darvis/supplydrive-api logs in, where the token is kept, the one repeat after a 401, what a 404 means, the statuses, timeouts and where the password goes."
---

# How it works

## The login

The Supplydrive portal uses OAuth 2.0 with a password grant. The client posts a form to the token endpoint:

```text
POST https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token
grant_type=password&client_id=SupplydrivePortal&username=…&password=…
```

The answer is a JWT with an `expires_in`. Every call to the status endpoint then carries `Authorization: Bearer <token>`.

The client is built without checking the account, so it can be injected anywhere; the first call that needs a token throws `Supplydrive API config is incomplete (username, password).` when there is none.

## Where the token is kept

Three places, in the order the client looks:

1. **On the instance.** The client is a singleton, so within one request or one process the token is fetched once.
2. **In Laravel's cache**, encrypted with the `APP_KEY` of the application, under `supplydrive.token.<sha256 of the token URL, the client id and the username>`. A web request finds the token the previous request fetched. The key is a hash, so the username is not readable for whoever lists the store.
3. **At the token endpoint**, when neither has one.

The token is kept until sixty seconds before it expires; a token that lives sixty seconds or less is not cached. Without `expires_in` in the token response the client assumes five minutes, which is what the portal hands out.

The cache only saves a login. When the store is down, does not exist, or the value cannot be decrypted (a rotated `APP_KEY`), the client logs one warning, `Supplydrive token cache is not available, the token is kept in memory only: …`, and works from memory. Nothing throws. `SUPPLYDRIVE_TOKEN_CACHE=false` turns the cache off; `SUPPLYDRIVE_TOKEN_CACHE_STORE` picks another store.

`$client->forgetToken()` drops the token in memory and in the cache; the next call logs in again. `php artisan cache:clear` has the same effect.

## The one repeat after a 401

A 401 on a token the client already had (from the instance or the cache) means the portal dropped the token before it expired. The client forgets it, logs in again and repeats the call once.

A 401 on a token it fetched for this very call is not about the token: the account has no access to the messages asked for, or lacks the role for the endpoint. That 401 is thrown as a `SupplydriveException` with status 401. Nothing else is retried: a 5xx or a timeout is the host application's decision, and a scheduled command simply tries again next run.

## 404 is not a failure

The status endpoint answers 404 when no messages match the filters. The client returns an empty list for it. The other statuses the endpoint documents:

| Status | What the client does |
| --- | --- |
| 200 | every item of the list becomes a `Message` |
| 404 | `[]` |
| 401 | a new login and one repeat when the token was not fresh; otherwise a `SupplydriveException` |
| other 4xx, 5xx | a `SupplydriveException` with that status |

## The filters

The endpoint takes five query parameters, and at least one is required: `functioneelId` (your reference, the invoice number), `berichtId` (the id Supplydrive gave the message), `debiteurKVK`, `debiteurGLN` and `debiteurBTW` (the debtor the interface is registered under). They can be combined; every message that matches all of them comes back. With only a debtor filter, Supplydrive returns the last hundred messages of that debtor.

The client checks the filters before it sends anything: a name it does not know, or no filter with a value, throws an `InvalidArgumentException`. That is a programming error, not a failure of Supplydrive, so it is not a `SupplydriveException`.

## The statuses

Supplydrive documents eight statuses. The API returns them as Dutch strings; the package maps them to `Darvis\SupplydriveApi\MessageStatus`:

| Case | API value | Meaning |
| --- | --- | --- |
| `Received` | `Ontvangen door supplydrive` | received and registered |
| `InProgress` | `In verwerking` | being processed |
| `DeliveryInRetry` | `Aflevering in retry` | delivery failed, retried periodically |
| `Delivered` | `Afgeleverd` | delivered |
| `DeliveredManually` | `Handmatig afgeleverd` | delivered or reprocessed by hand |
| `Undeliverable` | `Bericht kon niet afgeleverd worden` | delivery failed, Supplydrive investigates |
| `MappingFailed` | `Mapping gefaald` | the content is wrong, Supplydrive contacts the sender |
| `DeliveredToIntermediary` | `Afgeleverd bij tussenpartij` | delivered to an intermediary; their response may be in the portal dashboard |

`isDelivered()` is true for the three delivered cases, `isFailed()` for `Undeliverable` and `MappingFailed`, `isPending()` for the rest. A status the package does not know (Supplydrive adds one, say) stays a plain string on `Message::$status`, `knownStatus()` is null, and the message counts as pending, so a new status never makes an application record a delivery or a failure it did not see.

`description()` translates the status with the `supplydrive-api::status.*` lines, in English and Dutch.

## Timeouts

Every request, the token request included, gives up after 30 seconds and 10 seconds to connect (`SUPPLYDRIVE_TIMEOUT`, `SUPPLYDRIVE_CONNECT_TIMEOUT`). A timeout is an `Illuminate\Http\Client\ConnectionException`, not a `SupplydriveException`; catch both.

## Where the password goes

- The username and the password go in the form body of the token request, the token in the `Authorization` header. Neither is ever in a URL.
- Every exception message the package builds goes through `redactSecrets()`, which replaces the password and the token with `[redacted]`, also in their JSON and URL encoded forms. The rest of a response body is kept, and it can name customers and invoice numbers.
- The token goes in the cache encrypted only.
- `php artisan supplydrive:status` prints the messages, never the account.
