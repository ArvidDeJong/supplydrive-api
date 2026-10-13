<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Mcp\Tools;

use Darvis\SupplydriveApi\MessageStatus;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the statuses a Supplydrive message can have, as Supplydrive words them (in Dutch), with what each one means and whether it counts as delivered, failed or pending. Use it to explain a status to someone, or to decide what to do with one.')]
#[IsReadOnly]
class ExplainStatuses extends Tool
{
    public function handle(Request $request): Response
    {
        $lines = array_map(function (MessageStatus $status): string {
            $outcome = match (true) {
                $status->isDelivered() => 'delivered',
                $status->isFailed() => 'failed',
                default => 'pending',
            };

            return "{$status->value} ({$outcome}): ".$status->description();
        }, MessageStatus::cases());

        return Response::text(implode("\n", $lines)
            ."\n\nA status not in this list is new at Supplydrive; treat it as pending.");
    }
}
