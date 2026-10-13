<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

/**
 * The outcome of a health check of one Supplydrive endpoint. A check never throws; everything
 * it learned is in here.
 */
final readonly class HealthResult
{
    /**
     * @param  string  $endpoint  'portal' (status lookups) or 'send' (the send endpoint)
     * @param  bool  $reachable  Whether Supplydrive answered at all, whatever it answered
     * @param  bool|null  $authenticated  Whether the account was accepted; null when nothing was sent (incomplete config)
     * @param  int|null  $httpStatus  The HTTP status of the answer, null without an answer
     * @param  string  $message  One line for a screen or a terminal
     * @param  int  $durationMs  How long the check took
     */
    public function __construct(
        public string $endpoint,
        public bool $reachable,
        public ?bool $authenticated,
        public ?int $httpStatus,
        public string $message,
        public int $durationMs,
    ) {}

    /**
     * Reachable and the account was not refused.
     */
    public function ok(): bool
    {
        return $this->reachable && $this->authenticated === true;
    }
}
