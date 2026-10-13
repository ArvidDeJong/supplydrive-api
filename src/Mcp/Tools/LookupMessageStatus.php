<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Mcp\Tools;

use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\Message;
use Darvis\SupplydriveApi\SupplydriveClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Look up the delivery status of invoices or other messages sent through Supplydrive. Give at least one filter: functional_id (your reference, usually the invoice number), message_id (the id Supplydrive answered with when the message was sent), or a debtor by debtor_kvk, debtor_gln or debtor_vat. Filters combine; a debtor alone gives its last hundred messages. Every message comes back with its id, your reference, the status as Supplydrive words it, whether that means delivered, failed or pending, and an explanation. No match is an empty answer, not an error.')]
#[IsReadOnly]
#[IsOpenWorld]
class LookupMessageStatus extends Tool
{
    /**
     * The tool arguments and the query parameters of the Supplydrive API they map to.
     *
     * @var array<string, string>
     */
    protected const FILTERS = [
        'functional_id' => 'functioneelId',
        'message_id' => 'berichtId',
        'debtor_kvk' => 'debiteurKVK',
        'debtor_gln' => 'debiteurGLN',
        'debtor_vat' => 'debiteurBTW',
    ];

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'functional_id' => $schema->string()
                ->description('Your own reference of the message, usually the invoice number (the functioneelId filter).'),
            'message_id' => $schema->string()
                ->description('The id of the message within Supplydrive, the number Supplydrive answered with when it was sent (the berichtId filter).'),
            'debtor_kvk' => $schema->string()
                ->description('Chamber of Commerce (KVK) number of the debtor the interface is registered under.'),
            'debtor_gln' => $schema->string()
                ->description('GLN of the debtor the interface is registered under.'),
            'debtor_vat' => $schema->string()
                ->description('VAT number of the debtor the interface is registered under, for example NL123456789B01.'),
        ];
    }

    public function handle(Request $request, SupplydriveClient $client): Response
    {
        $validated = $request->validate(array_fill_keys(array_keys(static::FILTERS), ['nullable', 'string', 'max:255']));

        $filters = [];

        foreach (static::FILTERS as $argument => $parameter) {
            $value = trim((string) ($validated[$argument] ?? ''));

            if ($value !== '') {
                $filters[$parameter] = $value;
            }
        }

        if ($filters === []) {
            return Response::error('Give at least one filter: functional_id, message_id, debtor_kvk, debtor_gln or debtor_vat.');
        }

        try {
            $messages = $client->messageStatus($filters);
        } catch (ConnectionException $exception) {
            return Response::error('Supplydrive did not answer: '.$exception->getMessage());
        } catch (SupplydriveException $exception) {
            return Response::error($exception->getMessage());
        }

        $asked = implode(', ', array_map(fn (string $parameter, string $value): string => "{$parameter} {$value}", array_keys($filters), $filters));

        if ($messages === []) {
            return Response::text("No messages at Supplydrive match {$asked}. The reference may differ from what was sent, or the message was sent under another account.");
        }

        $blocks = array_map(fn (Message $message): string => $this->describe($message), $messages);

        return Response::text(count($messages).' message(s) for '.$asked.".\n\n".implode("\n\n", $blocks));
    }

    protected function describe(Message $message): string
    {
        $outcome = match (true) {
            $message->isDelivered() => 'delivered',
            $message->isFailed() => 'failed, somebody has to act',
            default => 'pending, ask again later',
        };

        $reference = $message->functionalId === null ? '' : ", functional id {$message->functionalId}";
        $interface = $message->interfaceId === null ? '' : ", interface {$message->interfaceId}";

        return "Message {$message->id}{$reference}{$interface}"
            ."\nStatus: {$message->status} ({$outcome})"
            ."\n".$message->description();
    }
}
