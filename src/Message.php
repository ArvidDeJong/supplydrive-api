<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

/**
 * One message as the status endpoint describes it. The fields Supplydrive documents are typed
 * properties; the full item is in $raw for anything the API adds later.
 */
final readonly class Message
{
    /**
     * @param  int  $id  The id of the message within Supplydrive (the "berichtId" filter)
     * @param  string|null  $functionalId  Your own reference, usually the invoice number (the "functioneelId" filter)
     * @param  string  $status  The status as the API returns it, in Dutch; see MessageStatus for the known values
     * @param  int|null  $interfaceId  The interface the message was received on
     * @param  array<string, mixed>  $raw  The item as the API returned it
     */
    public function __construct(
        public int $id,
        public ?string $functionalId,
        public string $status,
        public ?int $interfaceId,
        public array $raw = [],
    ) {}

    /**
     * Build a message from one item of the status response.
     *
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        return new self(
            id: (int) ($item['id'] ?? 0),
            functionalId: isset($item['functionalId']) ? (string) $item['functionalId'] : null,
            status: (string) ($item['status'] ?? ''),
            interfaceId: isset($item['interfaceId']) ? (int) $item['interfaceId'] : null,
            raw: $item,
        );
    }

    /**
     * The status as one of the documented values, or null when Supplydrive returned one the
     * package does not know.
     */
    public function knownStatus(): ?MessageStatus
    {
        return MessageStatus::tryFrom($this->status);
    }

    /**
     * What the status means, in the locale of the application. An unknown status gives the
     * translated "unknown status" line, never an exception.
     */
    public function description(): string
    {
        return $this->knownStatus()?->description() ?? MessageStatus::translate('unknown');
    }

    /**
     * The message reached the receiver, or an intermediary who delivers it further.
     */
    public function isDelivered(): bool
    {
        return $this->knownStatus()?->isDelivered() ?? false;
    }

    /**
     * Supplydrive gave up on the message; somebody has to act.
     */
    public function isFailed(): bool
    {
        return $this->knownStatus()?->isFailed() ?? false;
    }

    /**
     * Supplydrive is still working on the message, or returned a status the package does not
     * know. Ask again later.
     */
    public function isPending(): bool
    {
        return ! $this->isDelivered() && ! $this->isFailed();
    }

    /**
     * The documented fields plus the description, for a JSON response or a table.
     *
     * @return array{id: int, functionalId: string|null, status: string, interfaceId: int|null, description: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'functionalId' => $this->functionalId,
            'status' => $this->status,
            'interfaceId' => $this->interfaceId,
            'description' => $this->description(),
        ];
    }
}
