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
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => $timeout,
            'headers'  => $headers,
        ]);
    }

    /** JSON POST → decoded array. Retries on connect failure or 429/500/502/503. */
    public function post(string $path, array $payload): array
    {
        return $this->withRetry(function () use ($path, $payload): array {
            try {
                $response = $this->client->post(ltrim($path, '/'), ['json' => $payload]);
                return json_decode((string) $response->getBody(), true) ?? [];
            } catch (BadResponseException $e) {
                throw new ProviderRequestException(
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
                throw new ProviderRequestException(
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
     */
    private function withRetry(\Closure $fn): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $retryable = $e instanceof \RuntimeException
                    || ($e instanceof ProviderRequestException && in_array($e->getCode(), [429, 500, 502, 503], true));

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
