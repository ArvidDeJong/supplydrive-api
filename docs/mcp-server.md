---
title: "MCP server"
nav_order: 8
description: "Let Claude or another AI agent look up what happened to an invoice at Supplydrive through the local MCP server of darvis/supplydrive-api: handle and tools."
---

# MCP server

When `laravel/mcp` is installed, the package registers a local (stdio) MCP server. Laravel Boost already requires `laravel/mcp`, so an app with Boost has it. Without `laravel/mcp` the server is simply not registered; the client and the command keep working.

The server only reads. It looks up messages with the portal account of the app, and it cannot send or resend anything.

## Start it

```bash
php artisan mcp:start supplydrive
```

The handle `supplydrive` comes from `SUPPLYDRIVE_MCP_HANDLE`. Set `SUPPLYDRIVE_MCP_ENABLED=false` to leave the server out.

## Add it to your agent

For Claude Code, add it to `.mcp.json` in the root of the Laravel app:

```json
{
    "mcpServers": {
        "supplydrive": {
            "command": "php",
            "args": ["artisan", "mcp:start", "supplydrive"]
        }
    }
}
```

For the Claude desktop app and other clients, use the same command with the absolute path to `artisan`, for example `/Users/you/Sites/my-app/artisan`.

## The tools

### lookup-message-status

Looks up the delivery status of messages. It is marked read only. Give at least one argument; they combine.

| Argument | Type | Required | Supplydrive filter |
| --- | --- | --- | --- |
| `functional_id` | string | one of these | `functioneelId`: your reference, usually the invoice number |
| `message_id` | string | one of these | `berichtId`: the id Supplydrive answered with when the message was sent |
| `debtor_kvk` | string | one of these | `debiteurKVK` |
| `debtor_gln` | string | one of these | `debiteurGLN` |
| `debtor_vat` | string | one of these | `debiteurBTW` |

A debtor alone gives its last hundred messages. Every message comes back as a block:

```text
Message 42, functional id F260001, interface 1000
Status: Afgeleverd (delivered)
The message has been delivered.
```

The word between the brackets is what the agent should act on: `delivered`, `failed, somebody has to act` or `pending, ask again later`. A status the package does not know is reported as pending. No match is a plain answer (`No messages at Supplydrive match …`), not an error.

A refused login or a refused call comes back as a tool error with the message of the [exception](troubleshooting.md), with the password and the token redacted, so the agent can tell you what is wrong. A call without any filter is refused before anything is sent.

### explain-statuses

Takes no arguments and lists the eight statuses Supplydrive documents, as Supplydrive words them, each with `delivered`, `failed` or `pending` and its explanation in the locale of the application.

## Keep the agent in check

The account of the app sees every message of that account, so whoever can talk to the agent can see which invoices went to which debtor. Run the server where the app itself runs and for the people who may see its invoices. `SUPPLYDRIVE_MCP_ENABLED=false` turns it off on a server that should not expose it.
