---
title: "Installation"
nav_order: 2
description: "Install darvis/supplydrive-api in Laravel: the package, the portal account in .env, the optional config file and translations, and how to check that it works."
---

# Installation

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A Supplydrive portal account with the role for the status endpoint
- An `APP_KEY` and a cache store that persists, if you want the access token to be cached (a new Laravel application has both)

## 1. Install the package

```bash
composer require darvis/supplydrive-api
```

Laravel registers the service provider by itself (package discovery). There is nothing to add to `bootstrap/providers.php`, and the package has no migrations.

## 2. Get a portal account

The package logs in to the Supplydrive portal with a username and a password. Supplydrive creates that account for you; ask your contact there for "an account for the message status API". The account needs the role for the status endpoint, or every call answers with a 401.

The credentials you use to *send* invoices (the basic authentication of the messages URL) are a different account. They do not work here.

## 3. Add the account to `.env`

```env
SUPPLYDRIVE_USERNAME=your-username
SUPPLYDRIVE_PASSWORD=your-password
```

That is all a normal installation needs. The other settings have defaults:

| Variable | Default | What it does |
| --- | --- | --- |
| `SUPPLYDRIVE_BASE_URL` | `https://portal.supplydrive.com` | The portal. The status endpoint is `/SD/Berichten/Status` below it. |
| `SUPPLYDRIVE_TOKEN_URL` | `https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token` | The token endpoint. |
| `SUPPLYDRIVE_CLIENT_ID` | `SupplydrivePortal` | The OAuth client id Supplydrive documents. |
| `SUPPLYDRIVE_TIMEOUT` | `30` | Seconds to wait for a whole request, the token request included. |
| `SUPPLYDRIVE_CONNECT_TIMEOUT` | `10` | Seconds to wait for the connection. |
| `SUPPLYDRIVE_TOKEN_CACHE` | `true` | Keep the token in the cache between requests. `false` keeps it in memory only. |
| `SUPPLYDRIVE_TOKEN_CACHE_STORE` | the default store | A store from `config/cache.php`. |

After changing `.env` on a server with a cached config, run `php artisan config:cache` again.

## 4. Publish the config file (optional)

```bash
php artisan vendor:publish --tag=supplydrive-api-config
```

This writes `config/supplydrive.php`. Keep the keys as they are; the package reads them through `Darvis\SupplydriveApi\Support\SupplydriveConfig`.

## 5. Publish the translations (optional)

The descriptions of the statuses come in English and Dutch. To change a text:

```bash
php artisan vendor:publish --tag=supplydrive-api-lang
```

The files land in `lang/vendor/supplydrive-api/<locale>/status.php`.

## Check that it works

```bash
php artisan supplydrive:status --functional-id=F260001
```

Use the number of an invoice you sent through Supplydrive. With a working account the command prints:

```text
Looking up messages at Supplydrive...
✓ Connection successful, 1 message(s) found.
+----+---------------+------------+-----------+---------------------------------+
| Id | Functional id | Status     | Interface | Description                     |
+----+---------------+------------+-----------+---------------------------------+
| 1  | F260001       | Afgeleverd | 1000      | The message has been delivered. |
+----+---------------+------------+-----------+---------------------------------+
```

A number Supplydrive does not know still means the account works:

```text
✓ Connection successful, no messages match the filters.
```

The usual failures:

```text
✗ Request failed: Supplydrive API config is incomplete (username, password).
```

`SUPPLYDRIVE_USERNAME` or `SUPPLYDRIVE_PASSWORD` is missing, or the config is cached with the old values.

```text
✗ Request failed: Failed to retrieve access_token from Supplydrive. HTTP status: 401. Response: {"error":"invalid_grant","error_description":"Invalid user credentials"}
```

The portal did not accept the username and password.

```text
✗ Request failed: Supplydrive API call failed. HTTP status: 401. Response: ...
```

The login worked, but the account may not read these messages or lacks the role for the endpoint. Ask Supplydrive.

The [troubleshooting page](troubleshooting.md) has every message with its cause and fix.
