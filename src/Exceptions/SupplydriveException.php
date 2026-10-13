<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Exceptions;

/**
 * What the client throws when Supplydrive refuses or fails a request. The code is the HTTP status
 * of the response that caused it, or 0 when there was no response (incomplete config).
 *
 * The response body is not kept as a property: it can hold invoice data, and a property ends up
 * in error trackers that serialise exceptions. The message has the part that matters, with the
 * password and the token redacted.
 */
class SupplydriveException extends \RuntimeException
{
    /**
     * The HTTP status of the response that caused this, or 0 when there was no response.
     */
    public function status(): int
    {
        return (int) $this->getCode();
    }
}
