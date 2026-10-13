<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Console\Commands;

use Darvis\SupplydriveApi\HealthResult;
use Darvis\SupplydriveApi\SupplydriveClient;
use Darvis\SupplydriveApi\SupplydriveSender;
use Illuminate\Console\Command;

/**
 * Checks both Supplydrive endpoints without sending anything to the PEPPOL network. Exit code 0
 * when both answer and accept their account, 1 otherwise, so it fits a scheduler or a monitor.
 */
class HealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'supplydrive:health';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check whether the Supplydrive portal and send endpoint answer and accept the accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $results = [
            $this->laravel->make(SupplydriveClient::class)->healthCheck(),
            $this->laravel->make(SupplydriveSender::class)->healthCheck(),
        ];

        $this->table(
            ['Endpoint', 'Reachable', 'Account', 'HTTP', 'Time', 'Message'],
            array_map(fn (HealthResult $result) => [
                $result->endpoint,
                $result->reachable ? 'yes' : 'no',
                match ($result->authenticated) {
                    true => 'accepted',
                    false => 'refused',
                    null => 'unknown',
                },
                $result->httpStatus ?? '-',
                $result->durationMs.' ms',
                $result->message,
            ], $results),
        );

        $healthy = array_reduce($results, fn (bool $carry, HealthResult $result) => $carry && $result->ok(), true);

        if ($healthy) {
            $this->info('✓ Supplydrive is healthy.');

            return Command::SUCCESS;
        }

        $this->error('✗ Supplydrive is not healthy.');

        return Command::FAILURE;
    }
}
