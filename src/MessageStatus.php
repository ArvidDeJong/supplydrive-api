<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

/**
 * The statuses Supplydrive documents for a message. The values are the literal, Dutch strings the
 * API returns; a status the API adds later is not in here and comes back as a plain string on
 * the Message.
 */
enum MessageStatus: string
{
    case Received = 'Ontvangen door supplydrive';

    case InProgress = 'In verwerking';

    case DeliveryInRetry = 'Aflevering in retry';

    case Delivered = 'Afgeleverd';

    case DeliveredManually = 'Handmatig afgeleverd';

    case Undeliverable = 'Bericht kon niet afgeleverd worden';

    case MappingFailed = 'Mapping gefaald';

    case DeliveredToIntermediary = 'Afgeleverd bij tussenpartij';

    /**
     * The key of this status in the translation files, resources/lang/<locale>/status.php.
     */
    public function key(): string
    {
        return match ($this) {
            self::Received => 'received',
            self::InProgress => 'in_progress',
            self::DeliveryInRetry => 'delivery_in_retry',
            self::Delivered => 'delivered',
            self::DeliveredManually => 'delivered_manually',
            self::Undeliverable => 'undeliverable',
            self::MappingFailed => 'mapping_failed',
            self::DeliveredToIntermediary => 'delivered_to_intermediary',
        };
    }

    /**
     * What this status means, in the locale of the application (English and Dutch ship with the
     * package).
     */
    public function description(): string
    {
        return self::translate($this->key());
    }

    /**
     * A line of resources/lang/<locale>/status.php; an empty string when the translator has none.
     */
    public static function translate(string $key): string
    {
        $text = trans('supplydrive-api::status.'.$key);

        return is_string($text) ? $text : '';
    }

    /**
     * The message reached the receiver, or an intermediary who delivers it further.
     */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Delivered, self::DeliveredManually, self::DeliveredToIntermediary], true);
    }

    /**
     * Supplydrive gave up on the message; somebody has to act.
     */
    public function isFailed(): bool
    {
        return in_array($this, [self::Undeliverable, self::MappingFailed], true);
    }

    /**
     * Supplydrive is still working on the message. Ask again later.
     */
    public function isPending(): bool
    {
        return ! $this->isDelivered() && ! $this->isFailed();
    }
}
