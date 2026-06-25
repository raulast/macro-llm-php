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
    ) {
        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout'  => $timeout,
            'headers'  => $headers,
        ]);
    }

    /** JSON POST → decoded array. Throws ProviderRequestException on 4xx/5xx. */
    public function post(string $path, array $payload): array
    {
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

    /** Raw body string for SSE parsing. Throws ProviderRequestException on 4xx/5xx. */
    public function stream(string $path, array $payload): string
    {
        try {
            $response = $this->client->post(ltrim($path, '/'), [
                'json'    => $payload,
                'stream'  => true,
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
    }
}
