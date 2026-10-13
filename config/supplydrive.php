<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supplydrive API
    |--------------------------------------------------------------------------
    |
    | Read these values through Darvis\SupplydriveApi\Support\SupplydriveConfig.
    | The keys are in alphabetical order.
    |
    */

    // Base URL of the portal. The status endpoint is /SD/Berichten/Status below it.
    // The portal account (username, password) is for the status lookups only.
    'base_url' => env('SUPPLYDRIVE_BASE_URL', 'https://portal.supplydrive.com'),

    // OAuth client id of the portal. A fixed value Supplydrive documents; you normally keep it.
    'client_id' => env('SUPPLYDRIVE_CLIENT_ID', 'SupplydrivePortal'),

    // Seconds to wait for the connection to Supplydrive. Not a positive number: 10.
    'connect_timeout' => env('SUPPLYDRIVE_CONNECT_TIMEOUT', 10),

    // The local (stdio) MCP server, registered when laravel/mcp is installed. Start it with
    // php artisan mcp:start <handle>.
    'mcp' => [
        'enabled' => env('SUPPLYDRIVE_MCP_ENABLED', true),
        'handle' => env('SUPPLYDRIVE_MCP_HANDLE', 'supplydrive'),
    ],

    // Password of the portal account Supplydrive created for you. Sent in the body of the token request.
    'password' => env('SUPPLYDRIVE_PASSWORD'),

    // The send endpoint (REST, basic auth), where a UBL document is posted. This is a DIFFERENT
    // account than the portal login above: Supplydrive hands out the URL and the account per
    // customer, and the URL can differ per environment (LIVE, TEST).
    'send' => [
        'password' => env('SUPPLYDRIVE_SEND_PASSWORD'),
        'url' => env('SUPPLYDRIVE_SEND_URL', 'https://rest.supplydrive.com/PEPPOL-SD-MESSAGES-HTTP/LIVE'),
        'username' => env('SUPPLYDRIVE_SEND_USERNAME'),
    ],

    // Seconds to wait for a whole request, the token request included. Not a positive number: 30.
    'timeout' => env('SUPPLYDRIVE_TIMEOUT', 30),

    // The access token is kept, encrypted, in the cache until sixty seconds before it expires, so a
    // web request does not fetch its own. 'store' is a store from config/cache.php, null is the
    // default store. With 'enabled' false the token only lives in memory, on the client instance.
    'token_cache' => [
        'enabled' => env('SUPPLYDRIVE_TOKEN_CACHE', true),
        'store' => env('SUPPLYDRIVE_TOKEN_CACHE_STORE'),
    ],

    // Token endpoint of the portal (Keycloak, password grant).
    'token_url' => env('SUPPLYDRIVE_TOKEN_URL', 'https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token'),

    // Username of the portal account. Sent in the body of the token request.
    'username' => env('SUPPLYDRIVE_USERNAME'),

];
