<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi\Support;

/**
 * The one place that reads the package config. Callers ask this class, so a default is written
 * once and a caller cannot quietly disagree with config/supplydrive.php about what it is.
 */
final class SupplydriveConfig
{
    public const DEFAULT_BASE_URL = 'https://portal.supplydrive.com';

    public const DEFAULT_CLIENT_ID = 'SupplydrivePortal';

    public const DEFAULT_TOKEN_URL = 'https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token';

    public const DEFAULT_SEND_URL = 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE';

    /**
     * Base URL of the portal, without a trailing slash.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) config('supplydrive.base_url', self::DEFAULT_BASE_URL), '/');
    }

    /**
     * The OAuth client id sent with the token request. An empty value gives the default.
     */
    public static function clientId(): string
    {
        $clientId = config('supplydrive.client_id', self::DEFAULT_CLIENT_ID);

        return is_string($clientId) && $clientId !== '' ? $clientId : self::DEFAULT_CLIENT_ID;
    }

    /**
     * Seconds to wait for the connection. A value that is not a positive number gives the default.
     */
    public static function connectTimeout(): float
    {
        return self::positiveNumber(config('supplydrive.connect_timeout'), 10.0);
    }

    /**
     * Whether the local MCP server is registered (only when laravel/mcp is installed).
     */
    public static function mcpEnabled(): bool
    {
        $enabled = config('supplydrive.mcp.enabled', true);

        if ($enabled === null || $enabled === '') {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * The handle for php artisan mcp:start. An empty value gives the default.
     */
    public static function mcpHandle(): string
    {
        $handle = config('supplydrive.mcp.handle', 'supplydrive');

        return is_string($handle) && $handle !== '' ? $handle : 'supplydrive';
    }

    /**
     * The password of the portal account, or an empty string when it is not set.
     */
    public static function password(): string
    {
        return (string) config('supplydrive.password');
    }

    /**
     * The password of the account for the send endpoint, or an empty string when it is not set.
     */
    public static function sendPassword(): string
    {
        return (string) config('supplydrive.send.password');
    }

    /**
     * URL of the send endpoint. An empty value gives the default.
     */
    public static function sendUrl(): string
    {
        $url = config('supplydrive.send.url', self::DEFAULT_SEND_URL);

        return is_string($url) && $url !== '' ? $url : self::DEFAULT_SEND_URL;
    }

    /**
     * The username of the account for the send endpoint, or an empty string when it is not set.
     */
    public static function sendUsername(): string
    {
        return (string) config('supplydrive.send.username');
    }

    /**
     * Seconds to wait for a whole request. A value that is not a positive number gives the default.
     */
    public static function timeout(): float
    {
        return self::positiveNumber(config('supplydrive.timeout'), 30.0);
    }

    /**
     * Whether the access token is kept in the cache. Off means in memory on the instance only.
     */
    public static function tokenCacheEnabled(): bool
    {
        $enabled = config('supplydrive.token_cache.enabled', true);

        // An empty SUPPLYDRIVE_TOKEN_CACHE= line is "not set", and anything that is not a yes or a
        // no keeps the default.
        if ($enabled === null || $enabled === '') {
            return true;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * The cache store for the access token, or null for the default store of the application.
     */
    public static function tokenCacheStore(): ?string
    {
        $store = config('supplydrive.token_cache.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * URL of the token endpoint.
     */
    public static function tokenUrl(): string
    {
        return (string) config('supplydrive.token_url', self::DEFAULT_TOKEN_URL);
    }

    /**
     * The username of the portal account, or an empty string when it is not set.
     */
    public static function username(): string
    {
        return (string) config('supplydrive.username');
    }

    private static function positiveNumber(mixed $value, float $default): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }
}
