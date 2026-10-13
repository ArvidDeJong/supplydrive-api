<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Console\Commands;

use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Console\Command;

/**
 * Sends a UBL document from a file to Supplydrive. It is the connection test of the send account,
 * and it prints the response as it came, so you see what Supplydrive answers.
 *
 * It sends for real: the document ends up at the receiver.
 */
class SendDocumentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supplydrive:send
        {file : Path to the UBL document (XML)}
        {--functional-id= : Your reference, usually the invoice number, for the log lines}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a UBL document to Supplydrive for delivery over PEPPOL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            $file = is_scalar($file) ? (string) $file : '';
            $this->error("File not found or not readable: {$file}");

            return Command::INVALID;
        }

        $ublXml = (string) file_get_contents($file);

        $functionalId = $this->option('functional-id');
        $functionalId = is_string($functionalId) && trim($functionalId) !== '' ? trim($functionalId) : null;

        $this->info('Sending the document to Supplydrive...');

        try {
            // Resolved here and not injected, so a failure ends up below and not in a stack trace.
            $result = $this->laravel->make(SupplydriveSender::class)->send($ublXml, $functionalId);
        } catch (\Exception $e) {
            $this->error('✗ Send failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->info("✓ Document accepted, HTTP status {$result->httpStatus}.");
        $this->line('Message id: '.($result->messageId ?? '(not found in the response)'));
        $this->newLine();
        $this->line('Response:');
        $this->line($result->body === '' ? '(empty body)' : $result->body);

        return Command::SUCCESS;
    }
}
