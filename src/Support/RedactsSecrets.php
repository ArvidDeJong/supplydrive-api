<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Support;

/**
 * Replaces secrets in a message before it leaves the package. An error response can echo what was
 * sent, and the message ends up in logs and in command output.
 */
trait RedactsSecrets
{
    /**
     * Replace every given secret in the message. A secret is replaced as it is, as it looks inside
     * a JSON string, and as it looks in a form body or a URL.
     *
     * @param  list<string|null>  $secrets
     */
    protected function redact(string $message, array $secrets): string
    {
        $search = [];

        foreach ($secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $search[] = $secret;
            $search[] = trim((string) json_encode($secret), '"');
            $search[] = urlencode($secret);
            $search[] = rawurlencode($secret);
        }

        return str_replace(array_unique($search), '[redacted]', $message);
    }
}
