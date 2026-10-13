<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Mcp;

use Darvis\SupplydriveApi\Mcp\Tools\ExplainStatuses;
use Darvis\SupplydriveApi\Mcp\Tools\LookupMessageStatus;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('Supplydrive')]
#[Instructions('Looks up what happened to invoices and other messages this Laravel app sent through Supplydrive, a Dutch PEPPOL access point, with the portal account of the app. Use lookup-message-status with the invoice number (the functional id) or with the message id Supplydrive answered with when the message was sent; add a debtor to narrow it down, or give only a debtor for its last hundred messages. Every message comes back as delivered, failed or pending: pending means Supplydrive is still working on it, ask again later; failed means somebody has to act. explain-statuses lists every status with its meaning. Both tools only read; this server cannot send or resend anything, and it only sees the messages of the account of this app.')]
class SupplydriveServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        LookupMessageStatus::class,
        ExplainStatuses::class,
    ];
}
