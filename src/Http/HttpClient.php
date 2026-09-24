<?php

declare(strict_types=1);

namespace MacroLLM\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\BadResponseException;
use MacroLLM\Exception\ProviderRequestException;

final class HttpClient
{
    private readonly Client $client;

    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly int $timeout,
        private readonly int $retries = 0,
        private readonly int $retryDelayMs = 500,
        ?callable $handler = null,
        // Opt-in TCP connect bound (HC-1). Appended AFTER the handler seam so every existing
        // positional argument keeps its meaning (HC-8). The key is added only when a bound was
        // supplied: an omitted bound leaves the Guzzle config — and therefore the request
        // options — with no `connect_timeout` key at all, which is what keeps every existing
        // caller identical to the pre-change client. A supplied value is forwarded verbatim;
        // no clamping and no derivation from $timeout (HC-1).
        ?int $connectTimeout = null,
    ) {
        $config = [
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => $timeout,
            'headers'  => $headers,
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        if ($connectTimeout !== null) {
            $config['connect_timeout'] = $connectTimeout;
        }
        $this->client = new Client($config);
    }

    /** JSON POST → decoded array. Retries on connect failure or 429/500/502/503. */
    public function post(string $path, array $payload): array
    {
        return $this->withRetry(function () use ($path, $payload): array {
            try {
                $response = $this->client->post(ltrim($path, '/'), ['json' => $payload]);
                return json_decode((string) $response->getBody(), true) ?? [];
            } catch (BadResponseException $e) {
                // The transport knows the endpoint it called, never the provider behind it (HC-11).
                throw ProviderRequestException::forEndpoint(
                    $this->baseUrl,
                    $e->getResponse()->getStatusCode(),
                    (string) $e->getResponse()->getBody(),
                );
            } catch (ConnectException $e) {
                throw new \RuntimeException('Connection failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /** JSON GET → decoded array. Returns [] silently on any failure. */
    public function get(string $path): array
    {
        try {
            $response = $this->client->get(ltrim($path, '/'));
            if ($response->getStatusCode() >= 400) {
                return [];
            }
            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Raw body string for SSE parsing. Retries on connect failure or 429/500/502/503. */
    public function stream(string $path, array $payload): string
    {
        return $this->withRetry(function () use ($path, $payload): string {
            try {
                $response = $this->client->post(ltrim($path, '/'), [
                    'json'   => $payload,
                    'stream' => true,
                ]);
                return (string) $response->getBody();
            } catch (BadResponseException $e) {
                // Same contract as the JSON path: endpoint, not an invented provider name (HC-11).
                throw ProviderRequestException::forEndpoint(
                    $this->baseUrl,
                    $e->getResponse()->getStatusCode(),
                    (string) $e->getResponse()->getBody(),
                );
            } catch (ConnectException $e) {
                throw new \RuntimeException('Connection failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Runs $fn up to $retries+1 times with exponential backoff.
     * Retries on RuntimeException (ConnectException) and ProviderRequestException with status 429/500/502/503.
     * Does NOT retry on ProviderRequestException with other status codes (400, 404, 422, etc.).
     */
    private function withRetry(\Closure $fn): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                // ProviderRequestException with non-retryable status → never retry
                if ($e instanceof ProviderRequestException && ! in_array($e->getCode(), [429, 500, 502, 503], true)) {
                    throw $e;
                }

                // Retry only on RuntimeException (ConnectException wrapping) or retryable ProviderRequestException
                $retryable = $e instanceof \RuntimeException;

                if (!$retryable || $attempt >= $this->retries) {
                    throw $e;
                }

                $delayMs = (int) ($this->retryDelayMs * (2 ** $attempt));
                usleep($delayMs * 1000);
                $attempt++;
            }
        }
    }
}
