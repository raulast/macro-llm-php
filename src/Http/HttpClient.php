<?php

declare(strict_types=1);

namespace MacroLLM\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\BadResponseException;
use MacroLLM\Exception\ProviderRequestException;
use Psr\Http\Message\ResponseInterface;

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
        return $this->decode($this->request(
            fn (): ResponseInterface => $this->client->post(ltrim($path, '/'), ['json' => $payload]),
        ));
    }

    /**
     * JSON POST → the response body UNPARSED.
     *
     * Named for the response, not for the request: the request body is JSON, exactly like `post()`.
     * Use it when the endpoint answers with bytes instead of JSON — TTS endpoints return audio, and
     * `post()` would `json_decode()` it into an empty array. That is precisely why the audio
     * providers used to build their own Guzzle clients to escape this class.
     */
    public function postRaw(string $path, array $payload): string
    {
        return (string) $this->request(
            fn (): ResponseInterface => $this->client->post(ltrim($path, '/'), ['json' => $payload]),
        )->getBody();
    }

    /**
     * Multipart POST → decoded array.
     *
     * Retry-safe by construction, which is the entire reason this method exists. Building a
     * multipart body CONSUMES every stream part, so a retry that reused the same parts would upload
     * empty fields and still report success. Guzzle does not protect against that:
     * `guzzlehttp/psr7`'s `MultipartStream::addElement()` calls `Utils::streamFor()` on whatever
     * `contents` holds — there is no callable branch and no rewind. So the parts are re-resolved
     * before EVERY attempt:
     *
     *  - `contents` as a `Closure` is called again — the recommended form for files:
     *    `['name' => 'file', 'contents' => fn () => fopen($path, 'r'), 'filename' => 'audio.mp3']`
     *  - `contents` as a seekable resource is rewound before it is sent again;
     *  - a NON-seekable resource (socket, `php://stdin`) cannot be rewound and is therefore **not**
     *    retry-safe: pass a Closure when the payload has to survive a retry.
     *
     * A string `contents` is retry-safe by definition. A `Closure` is matched by type and not with
     * `is_callable()`, because a string such as `'trim'` is callable and would be invoked instead
     * of sent.
     *
     * @param  array<int, array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}>  $multipart
     * @return array<string, mixed>
     */
    public function postMultipart(string $path, array $multipart): array
    {
        return $this->decode($this->request(
            fn (): ResponseInterface => $this->client->post(ltrim($path, '/'), [
                'multipart' => $this->resolveParts($multipart),
            ]),
        ));
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
        $response = $this->request(
            fn (): ResponseInterface => $this->client->post(ltrim($path, '/'), [
                'json'   => $payload,
                'stream' => true,
            ]),
        );

        return (string) $response->getBody();
    }

    /**
     * Repeat one HTTP attempt under the shared retry policy and failure contract.
     *
     * The CLOSURE is what the retry loop repeats, never a pre-built request body. That is what makes
     * a multipart payload retry-safe: `resolveParts()` runs inside the closure, so every attempt gets
     * parts that were re-resolved after the previous attempt consumed them. Resolving the parts once,
     * outside this loop, is the bug this shape exists to prevent.
     *
     * @param  callable(): ResponseInterface  $send
     */
    private function request(callable $send): ResponseInterface
    {
        return $this->withRetry(fn (): ResponseInterface => $this->guard($send));
    }

    /**
     * One HTTP attempt under the shared failure contract.
     *
     * The transport knows the endpoint it called and never the provider behind it, so a failure is
     * reported as an endpoint and left unattributed for the caller that resolved the provider to
     * attribute (HC-11).
     */
    private function guard(callable $attempt): ResponseInterface
    {
        try {
            return $attempt();
        } catch (BadResponseException $e) {
            throw ProviderRequestException::forEndpoint(
                $this->baseUrl,
                $e->getResponse()->getStatusCode(),
                (string) $e->getResponse()->getBody(),
            );
        } catch (ConnectException $e) {
            throw new \RuntimeException('Connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Re-resolve every part so the next attempt gets an unread payload.
     *
     * @param  array<int, array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}>  $multipart
     * @return array<int, array{name: string, contents: mixed, filename?: string, headers?: array<string, string>}>
     */
    private function resolveParts(array $multipart): array
    {
        foreach ($multipart as $index => $part) {
            $contents = $part['contents'] ?? null;

            if ($contents instanceof \Closure) {
                $multipart[$index]['contents'] = $contents();
                continue;
            }

            if (is_resource($contents) && stream_get_meta_data($contents)['seekable'] === true) {
                rewind($contents);
            }
        }

        return $multipart;
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
