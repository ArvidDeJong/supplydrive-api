---
title: "Home"
nav_order: 1
description: "darvis/supplydrive-api is a Laravel client for the Supplydrive Portal API: log in, keep the token, and look up what happened to an invoice sent via Supplydrive."
permalink: /
---

# Supplydrive API for Laravel

`darvis/supplydrive-api` is a client for the status endpoint of the Supplydrive Portal API. [Supplydrive](https://www.supplydrive.cloud) is a Dutch PEPPOL access point and EDI provider: you send them an invoice, they deliver it to the receiver. This package asks their portal what happened to it.

It is for a Laravel developer whose application sends invoices through Supplydrive and wants to show, record or act on the delivery.

## What it does

- **Login.** The portal account is exchanged for an access token (OAuth password grant). The token is kept encrypted in the cache until sixty seconds before it expires, and the client logs in again once when the portal refuses it.
- **Six lookups**: `byFunctionalId()` (your invoice number), `byMessageId()` (the id Supplydrive answered with), `byDebtorKvk()`, `byDebtorGln()`, `byDebtorVat()` and `messageStatus()` for any combination of filters.
- **Typed results**: a `Message` per item, and a `MessageStatus` enum for the eight statuses Supplydrive documents, with `isDelivered()`, `isFailed()` and `isPending()` and a description in English or Dutch.
- **Failures you can act on**: a `SupplydriveException` with the HTTP status in `$e->status()`; a 404 is simply an empty list.
- **The password stays out of sight**: never in a URL, and removed from error messages before they are thrown, logged or printed.
- **A connection test**: `php artisan supplydrive:status`.
- **An MCP server** for Claude and other AI agents, with `lookup-message-status` and `explain-statuses`, registered when `laravel/mcp` is installed.

## What it does not do

- No sending. Sending an invoice through Supplydrive is a `POST` with basic authentication to another URL; [darvis/ubl-peppol](https://github.com/ArvidDeJong/ubl-peppol) does that.
- No Eloquent models, no migrations and no database tables. You get `Message` objects back and store what you need yourself.
- No polling, no scheduler and no queue jobs. When and how often you ask Supplydrive is up to your application.
- No webhooks and no routes. The MCP server only reads.
- No retry for a 5xx or a timeout.
- No facade.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13
- A Supplydrive portal account with the role for the status endpoint; ask Supplydrive for one

## Install

```bash
composer require darvis/supplydrive-api
```

```env
SUPPLYDRIVE_USERNAME=your-username
SUPPLYDRIVE_PASSWORD=your-password
```

```bash
php artisan supplydrive:status --functional-id=F260001
```

## Pages

- [Installation](installation.md): the steps from `composer require` to a lookup that works, and how to check it
- [Quick start](quickstart.md): one complete example, a scheduled command that records the delivery on the invoice
- [How it works](concepts.md): the login, where the token is kept, the one repeat after a 401, the statuses, timeouts and where the password goes
- [API reference](api-reference.md): every public method, the value object, the enum, the exception, the command and the container binding
- [Testing](testing.md): fake Supplydrive in the tests of your application
- [MCP server](mcp-server.md): let an AI agent look messages up through `php artisan mcp:start supplydrive`
- [Troubleshooting](troubleshooting.md): every message the package gives, with its cause and its fix
- [FAQ](faq.md): short answers to the questions people ask first

## Links

- [Source on GitHub](https://github.com/ArvidDeJong/supplydrive-api)
- [Packagist](https://packagist.org/packages/darvis/supplydrive-api)
- [Changelog](https://github.com/ArvidDeJong/supplydrive-api/blob/main/CHANGELOG.md)
- [Report an issue](https://github.com/ArvidDeJong/supplydrive-api/issues)

This is an unofficial package, not made or endorsed by Supplydrive B.V.
