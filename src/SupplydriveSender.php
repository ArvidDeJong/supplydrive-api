<?php

declare(strict_types=1);

namespace Darvis\SupplydriveApi;

use Darvis\SupplydriveApi\Exceptions\SupplydriveException;
use Darvis\SupplydriveApi\Support\RedactsSecrets;
use Darvis\SupplydriveApi\Support\SupplydriveConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a UBL document (an invoice, a credit note) to the send endpoint of Supplydrive, which
 * delivers it over PEPPOL. The endpoint takes basic auth with the account Supplydrive created for
 * sending; that is another account than the portal login the status client uses.
 *
 * It sends with Laravel's HTTP client, so host apps fake it with Http::fake().
 */
class SupplydriveSender
{
    use RedactsSecrets;

    protected string $url;

    protected string $username;

    protected string $password;

    protected float $timeout;

    protected float $connectTimeout;

    public function __construct()
    {
        $this->url = SupplydriveConfig::sendUrl();
        $this->username = SupplydriveConfig::sendUsername();
        $this->password = SupplydriveConfig::sendPassword();
        $this->timeout = SupplydriveConfig::timeout();
        $this->connectTimeout = SupplydriveConfig::connectTimeout();
    }

    /**
     * POST the document. A 2xx gives the result with the message id Supplydrive answered with.
     *
     * Nothing is retried: a POST is not idempotent, and a document that was sent twice is delivered
     * twice. A timeout may mean the document was received all the same; look it up by your own
     * reference (SupplydriveClient::byFunctionalId()) before you send it again.
     *
     * @param  string  $ublXml  The UBL document
     * @param  string|null  $functionalId  Your own reference, usually the invoice number; only used in log lines
     *
     * @throws SupplydriveException when the account is not configured, or Supplydrive refuses the document
     * @throws ConnectionException when Supplydrive cannot be reached or does not answer in time
     */
    public function send(string $ublXml, ?string $functionalId = null): SendResult
    {
        if ($this->username === '' || $this->password === '') {
            throw new SupplydriveException('Supplydrive send config is incomplete (send.username, send.password).');
        }

        if (trim($ublXml) === '') {
            throw new \InvalidArgumentException('The UBL document to send is empty.');
        }

        Log::info('Supplydrive: sending document', ['functional_id' => $functionalId]);

        $response = Http::withBasicAuth($this->username, $this->password)
            ->withOptions([
                'connect_timeout' => $this->connectTimeout,
                'timeout' => $this->timeout,
            ])
            ->withHeaders([
                'Content-Type' => 'application/xml',
                'Accept' => 'application/json',
            ])
            ->withBody($ublXml, 'application/xml')
            ->post($this->url);

        // The body can hold invoice data: keep it out of the application log.
        Log::info('Supplydrive: response received', ['functional_id' => $functionalId, 'status_code' => $response->status()]);

        if ($response->failed()) {
            $this->handleError($response);
        }

        return SendResult::fromResponse($response->status(), $response->body());
    }

    /**
     * Whether the send endpoint answers and does not refuse the account: one GET with basic auth
     * and no document. The endpoint only knows POST, so any answer (also a 405 or a 400) means it
     * is up; a 401 or 403 means the account is wrong. Nothing is sent to the PEPPOL network.
     * Never throws.
     */
    public function healthCheck(): HealthResult
    {
        $started = hrtime(true);
        $elapsed = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        if ($this->username === '' || $this->password === '') {
            return new HealthResult('send', false, null, null, 'Supplydrive send config is incomplete (send.username, send.password).', $elapsed());
        }

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->withOptions([
                    'connect_timeout' => $this->connectTimeout,
                    'timeout' => $this->timeout,
                ])
                ->acceptJson()
                ->get($this->url);
        } catch (ConnectionException $e) {
            return new HealthResult('send', false, null, null, 'Supplydrive send endpoint did not answer: '.$this->redactSecrets($e->getMessage()), $elapsed());
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            return new HealthResult('send', true, false, $status, "Supplydrive send endpoint refused the account (HTTP {$status}).", $elapsed());
        }

        if ($status >= 500) {
            return new HealthResult('send', true, null, $status, "Supplydrive send endpoint answers with an error (HTTP {$status}).", $elapsed());
        }

        return new HealthResult('send', true, true, $status, "Supplydrive send endpoint answers (HTTP {$status}) and did not refuse the account.", $elapsed());
    }

    protected function handleError(Response $response): void
    {
        $status = $response->status();
        $body = $response->json() ?? $response->body();

        $message = "Supplydrive send failed. HTTP status: {$status}.";

        if (is_array($body)) {
            $message .= ' Response: '.json_encode($body);
        } elseif ($body !== '') {
            $message .= ' Response: '.$body;
        }

        throw new SupplydriveException($this->redactSecrets($message), $status);
    }

    /**
     * Replace the password of the send account in a message.
     */
    protected function redactSecrets(string $message): string
    {
        return $this->redact($message, [$this->password]);
    }
}
