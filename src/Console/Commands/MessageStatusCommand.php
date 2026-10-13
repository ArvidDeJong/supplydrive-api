<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Console\Commands;

use Darvis\SupplydriveApi\Message;
use Darvis\SupplydriveApi\SupplydriveClient;
use Illuminate\Console\Command;

/**
 * Looks up messages at Supplydrive from the terminal. It is the connection test of the package:
 * one option, one call with your account, and the same message your code would get.
 */
class MessageStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supplydrive:status
        {--functional-id= : Your reference, usually the invoice number (functioneelId)}
        {--message-id= : The id of the message within Supplydrive (berichtId)}
        {--kvk= : Chamber of Commerce number of the debtor (debiteurKVK)}
        {--gln= : GLN of the debtor (debiteurGLN)}
        {--vat= : VAT number of the debtor (debiteurBTW)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Look up the status of messages at Supplydrive';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filters = [
            'functioneelId' => $this->option('functional-id'),
            'berichtId' => $this->option('message-id'),
            'debiteurKVK' => $this->option('kvk'),
            'debiteurGLN' => $this->option('gln'),
            'debiteurBTW' => $this->option('vat'),
        ];

        $filters = array_filter($filters, fn ($value) => is_string($value) && trim($value) !== '');

        if ($filters === []) {
            $this->error('Give at least one filter: --functional-id, --message-id, --kvk, --gln or --vat.');

            return Command::INVALID;
        }

        $this->info('Looking up messages at Supplydrive...');

        try {
            // Resolved here and not injected, so a failure ends up below and not in a stack trace.
            $messages = $this->laravel->make(SupplydriveClient::class)->messageStatus($filters);
        } catch (\Exception $e) {
            $this->error('✗ Request failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($messages === []) {
            $this->info('✓ Connection successful, no messages match the filters.');

            return Command::SUCCESS;
        }

        $this->info('✓ Connection successful, '.count($messages).' message(s) found.');

        $this->table(
            ['Id', 'Functional id', 'Status', 'Interface', 'Description'],
            array_map(fn (Message $message) => [
                $message->id,
                $message->functionalId ?? '',
                $message->status,
                $message->interfaceId ?? '',
                $message->description(),
            ], $messages),
        );

        return Command::SUCCESS;
    }
}
