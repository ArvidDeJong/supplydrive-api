<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

use Carbon\Carbon;
use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\Support\RedactsSecrets;
use Darvis\SupplydriveApi\Support\SupplydriveConfig;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Supplydrive Portal API. It logs in with the portal account (OAuth password
 * grant), keeps the access token, and asks the status endpoint about the messages Supplydrive
 * processed for you: PEPPOL invoices, mostly.
 *
 * It sends its requests with Laravel's HTTP client, so host apps fake it with Http::fake().
 */
class SupplydriveClient
{
    use RedactsSecrets;

    /**
     * The path of the status endpoint below the base URL.
     */
    public const STATUS_PATH = '/SD/Berichten/Status';

    /**
     * The query parameters the status endpoint accepts, in the spelling of the API.
     *
     * @var list<string>
     */
    public const FILTERS = ['functioneelId', 'berichtId', 'debiteurKVK', 'debiteurGLN', 'debiteurBTW'];

    protected string $baseUrl;

    protected string $tokenUrl;

    protected string $clientId;

    protected string $username;

    protected string $password;

    protected float $timeout;

    protected float $connectTimeout;

    protected bool $tokenCacheEnabled;

    protected ?string $tokenCacheStore;

    protected ?string $accessToken = null;

    protected ?Carbon $tokenExpiresAt = null;

    /**
     * True when the token in memory was fetched from the token endpoint during the current call,
     * false when it was held from an earlier call or came from the cache.
     */
    protected bool $tokenIsFresh = false;

    protected bool $tokenCacheWarningLogged = false;

    public function __construct()
    {
        $this->baseUrl = SupplydriveConfig::baseUrl();
        $this->tokenUrl = SupplydriveConfig::tokenUrl();
        $this->clientId = SupplydriveConfig::clientId();
        $this->username = SupplydriveConfig::username();
        $this->password = SupplydriveConfig::password();
        $this->timeout = SupplydriveConfig::timeout();
        $this->connectTimeout = SupplydriveConfig::connectTimeout();
        $this->tokenCacheEnabled = SupplydriveConfig::tokenCacheEnabled();
        $this->tokenCacheStore = SupplydriveConfig::tokenCacheStore();
    }

    /* -----------------------------------------------------------------
     |  Status lookups
     | -----------------------------------------------------------------
     */

    /**
     * The messages with your reference, usually the invoice number, as the functional id.
     *
     * @return list<Message>
     */
    public function byFunctionalId(string $functionalId): array
    {
        return $this->messageStatus(['functioneelId' => $functionalId]);
    }

    /**
     * The message with this id within Supplydrive. That is the number Supplydrive answers with
     * when you send a message, so the list has at most one item.
     *
     * @return list<Message>
     */
    public function byMessageId(int|string $messageId): array
    {
        return $this->messageStatus(['berichtId' => (string) $messageId]);
    }

    /**
     * The last hundred messages of the debtor with this Chamber of Commerce (KVK) number.
     *
     * @return list<Message>
     */
    public function byDebtorKvk(string $kvkNumber): array
    {
        return $this->messageStatus(['debiteurKVK' => $kvkNumber]);
    }

    /**
     * The last hundred messages of the debtor with this GLN.
     *
     * @return list<Message>
     */
    public function byDebtorGln(string $gln): array
    {
        return $this->messageStatus(['debiteurGLN' => $gln]);
    }

    /**
     * The last hundred messages of the debtor with this VAT number.
     *
     * @return list<Message>
     */
    public function byDebtorVat(string $vatNumber): array
    {
        return $this->messageStatus(['debiteurBTW' => $vatNumber]);
    }

    /**
     * GET /SD/Berichten/Status with any combination of the filters the API accepts:
     * functioneelId, berichtId, debiteurKVK, debiteurGLN and debiteurBTW. Filters with an empty
     * value are left out; at least one has to remain. A 404 means "no messages" and gives an
     * empty list. When only a debtor filter is given, Supplydrive returns its last hundred messages.
     *
     * @param  array<string, mixed>  $filters
     * @return list<Message>
     *
     * @throws \InvalidArgumentException when no filter is given or a filter is not one the API knows
     * @throws SupplydriveException when Supplydrive refuses the login or the call
     * @throws ConnectionException when Supplydrive cannot be reached or does not answer in time
     */
    public function messageStatus(array $filters): array
    {
        $query = $this->normaliseFilters($filters);

        $response = $this->sendRequest($query);

        // A 401 on a token that was held or cached: the portal dropped it before it expired. Get a
        // new one and repeat the call, once. A 401 on a token of a moment ago is not about the token.
        if ($response->status() === 401 && ! $this->tokenIsFresh) {
            $this->forgetToken();

            $response = $this->sendRequest($query);
        }

        if ($response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            $this->handleError($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            return [];
        }

        // The endpoint answers with a list. Be lenient with one object: treat it as a list of one.
        if (array_is_list($data) === false) {
            $data = [$data];
        }

        $messages = [];

        foreach ($data as $item) {
            if (is_array($item)) {
                $messages[] = Message::fromArray($item);
            }
        }

        return $messages;
    }

    /**
     * Keep the filters the API knows, drop the empty ones, and insist on at least one.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    protected function normaliseFilters(array $filters): array
    {
        $query = [];

        foreach ($filters as $name => $value) {
            if (! in_array($name, static::FILTERS, true)) {
                throw new \InvalidArgumentException(
                    "Unknown Supplydrive filter '{$name}'. Use one of: ".implode(', ', static::FILTERS).'.'
                );
            }

            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($value !== '') {
                $query[$name] = $value;
            }
        }

        if ($query === []) {
            throw new \InvalidArgumentException(
                'At least one Supplydrive filter is required: '.implode(', ', static::FILTERS).'.'
            );
        }

        return $query;
    }

    /**
     * Send one status request with the current token, or with a new one when there is none.
     *
     * @param  array<string, string>  $query
     */
    protected function sendRequest(array $query): Response
    {
        $token = $this->getAccessToken();

        return $this->withTimeouts(Http::withToken($token))
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->get(static::STATUS_PATH, $query);
    }

    protected function withTimeouts(PendingRequest $request): PendingRequest
    {
        return $request->withOptions([
            'connect_timeout' => $this->connectTimeout,
            'timeout' => $this->timeout,
        ]);
    }

    /* -----------------------------------------------------------------
     |  Token handling (grant_type = password)
     | -----------------------------------------------------------------
     */

    /**
     * Drop the access token, in memory and in the cache. The next call fetches a new one.
     */
    public function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = null;
        $this->tokenIsFresh = false;

        $this->tokenCache(fn (Repository $cache) => $cache->forget($this->tokenCacheKey()));
    }

    protected function getAccessToken(): string
    {
        if (
            $this->accessToken !== null &&
            $this->tokenExpiresAt !== null &&
            $this->tokenExpiresAt->isFuture()
        ) {
            $this->tokenIsFresh = false;

            return $this->accessToken;
        }

        $cached = $this->restoreTokenFromCache();

        if ($cached !== null) {
            $this->tokenIsFresh = false;

            return $cached;
        }

        return $this->fetchAccessToken();
    }

    /**
     * Log in at the token endpoint, keep the token in memory and put it in the cache.
     */
    protected function fetchAccessToken(): string
    {
        if ($this->username === '' || $this->password === '') {
            throw new SupplydriveException('Supplydrive API config is incomplete (username, password).');
        }

        $response = $this->withTimeouts(Http::asForm())
            ->acceptJson()
            ->post($this->tokenUrl, [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'username' => $this->username,
                'password' => $this->password,
            ]);

        if ($response->failed()) {
            $this->handleError($response, 'Failed to retrieve access_token from Supplydrive.');
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['access_token']) || ! is_scalar($data['access_token'])) {
            throw new SupplydriveException('Supplydrive token response does not contain access_token.', $response->status());
        }

        $token = (string) $data['access_token'];

        $this->accessToken = $token;
        $this->tokenIsFresh = true;

        $expiresIn = isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : 300;
        $this->tokenExpiresAt = Carbon::now()->addSeconds($expiresIn - 60);

        $this->storeTokenInCache();

        return $token;
    }

    /**
     * The key differs per token URL and per account, so two accounts never share a token. Both
     * are hashed: a cache key is readable for whoever can list the store.
     */
    protected function tokenCacheKey(): string
    {
        return 'supplydrive.token.'.hash('sha256', $this->tokenUrl.'|'.$this->clientId.'|'.$this->username);
    }

    /**
     * Take the token from the cache into memory and return it, or null. Anything that is not a
     * token this application encrypted, and that is still valid, counts as no token and is removed.
     */
    protected function restoreTokenFromCache(): ?string
    {
        $cached = $this->tokenCache(fn (Repository $cache) => $cache->get($this->tokenCacheKey()));

        if ($cached === null) {
            return null;
        }

        try {
            $data = is_string($cached) ? json_decode(Crypt::decryptString($cached), true) : null;
        } catch (\Throwable) {
            // Encrypted with another APP_KEY, or not encrypted at all.
            $data = null;
        }

        if (
            is_array($data) &&
            is_string($data['access_token'] ?? null) &&
            $data['access_token'] !== '' &&
            is_int($data['expires_at'] ?? null) &&
            $data['expires_at'] > Carbon::now()->getTimestamp()
        ) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpiresAt = Carbon::createFromTimestamp($data['expires_at']);

            return $this->accessToken;
        }

        $this->tokenCache(fn (Repository $cache) => $cache->forget($this->tokenCacheKey()));

        return null;
    }

    /**
     * Put the token in the cache, encrypted, for as long as the instance itself would keep it.
     */
    protected function storeTokenInCache(): void
    {
        if ($this->accessToken === null || $this->tokenExpiresAt === null || ! $this->tokenExpiresAt->isFuture()) {
            return;
        }

        $token = $this->accessToken;
        $expiresAt = $this->tokenExpiresAt->getTimestamp();

        // Whole seconds, counted here: how a cache repository turns a date into a number of seconds
        // differs between Laravel and Carbon versions, and rounding down costs the last second.
        $seconds = $expiresAt - Carbon::now()->getTimestamp();

        if ($seconds < 1) {
            return;
        }

        $this->tokenCache(fn (Repository $cache) => $cache->put(
            $this->tokenCacheKey(),
            Crypt::encryptString((string) json_encode(['access_token' => $token, 'expires_at' => $expiresAt])),
            $seconds,
        ));
    }

    /**
     * Run something against the token cache. The cache only saves a token request: when it is
     * turned off, or the store is down or does not exist, the client works from memory.
     *
     * @param  callable(Repository): mixed  $callback
     */
    protected function tokenCache(callable $callback): mixed
    {
        if (! $this->tokenCacheEnabled) {
            return null;
        }

        try {
            return $callback(Cache::store($this->tokenCacheStore));
        } catch (\Throwable $e) {
            if (! $this->tokenCacheWarningLogged) {
                $this->tokenCacheWarningLogged = true;

                Log::warning('Supplydrive token cache is not available, the token is kept in memory only: '.$this->redactSecrets($e->getMessage()));
            }

            return null;
        }
    }

    /* -----------------------------------------------------------------
     |  Error handling
     | -----------------------------------------------------------------
     */

    protected function handleError(Response $response, ?string $prefixMessage = null): void
    {
        $status = $response->status();
        $body = $response->json() ?? $response->body();

        $message = $prefixMessage ?: 'Supplydrive API call failed.';
        $message .= " HTTP status: {$status}.";

        if (is_array($body)) {
            $message .= ' Response: '.json_encode($body);
        } elseif ($body !== '') {
            $message .= ' Response: '.$body;
        }

        throw new SupplydriveException($this->redactSecrets($message), $status);
    }

    /**
     * Replace the password and the access token in a message. An error response can echo what
     * was sent, and the message ends up in logs and in command output.
     */
    protected function redactSecrets(string $message): string
    {
        return $this->redact($message, [$this->password, $this->accessToken]);
    }
}
