<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

/**
 * What Supplydrive answered when a document was accepted (a 2xx). A refused document never gives
 * a result; the sender throws for it.
 *
 * The message id is the key for the status history of the document: look it up with
 * SupplydriveClient::byMessageId(). Store it on your own model, next to the moment of sending.
 */
final readonly class SendResult
{
    /**
     * The keys under which the message id is looked for, in order of preference.
     *
     * @var list<string>
     */
    public const MESSAGE_ID_KEYS = ['berichtId', 'berichtID', 'BerichtId', 'messageId', 'message_id', 'MessageId', 'id', 'Id', 'ID'];

    /**
     * @param  int  $httpStatus  The HTTP status of the response, a 2xx
     * @param  string|null  $messageId  The id Supplydrive gave the message, or null when none was found in the body
     * @param  string  $body  The response body as Supplydrive sent it
     * @param  array<array-key, mixed>|null  $data  The body decoded, when it is JSON
     */
    public function __construct(
        public int $httpStatus,
        public ?string $messageId,
        public string $body,
        public ?array $data,
    ) {}

    /**
     * Build a result from a response body.
     */
    public static function fromResponse(int $httpStatus, string $body): self
    {
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? $decoded : null;

        return new self(
            httpStatus: $httpStatus,
            messageId: self::extractMessageId($data ?? $decoded ?? $body),
            body: $body,
            data: $data,
        );
    }

    /**
     * Whether Supplydrive answered with a message id. Without one the document was accepted all the
     * same; only the exact lookup by id is not possible, the lookup by your own reference still is.
     */
    public function hasMessageId(): bool
    {
        return $this->messageId !== null;
    }

    /**
     * Pick the message id out of the body: a JSON object with the id at the top level or one level
     * down, a body that is only a number, or a bare JSON number.
     *
     * The shape of the response is not documented by Supplydrive. These are the likely shapes; a
     * body that matches none gives null, and the raw body stays available for a look.
     */
    public static function extractMessageId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' && ctype_digit($value) ? $value : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (self::MESSAGE_ID_KEYS as $key) {
            if (self::isId($value[$key] ?? null)) {
                return trim((string) $value[$key]);
            }
        }

        foreach ($value as $nested) {
            if (! is_array($nested)) {
                continue;
            }

            foreach (self::MESSAGE_ID_KEYS as $key) {
                if (self::isId($nested[$key] ?? null)) {
                    return trim((string) $nested[$key]);
                }
            }
        }

        return null;
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && trim($value) !== '');
    }
}
