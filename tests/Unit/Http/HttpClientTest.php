<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for HttpClient using the ?callable $handler seam (Approach C).
 *
 * All tests use GuzzleHttp\Handler\MockHandler + HandlerStack so no real
 * network connection is made. retryDelayMs=0 prevents any real usleep wait.
 */
final class HttpClientTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Helper: build an HttpClient wired to a MockHandler + request history
    // ---------------------------------------------------------------------------

    /**
     * @param  list<\GuzzleHttp\Psr7\Response|\Throwable>  $queue     Responses or exceptions in order
     * @param  array<int, array{request: \Psr\Http\Message\RequestInterface, response?: mixed}>  &$history  Populated with each sent request
     * @param  int  $retries        Number of retry attempts (not counting the initial attempt)
     * @param  int  $retryDelayMs   Delay in ms between retries; use 0 in tests
     */
    private function makeClient(
        array $queue,
        array &$history = [],
        int $retries = 0,
        int $retryDelayMs = 0,
    ): HttpClient {
        $mock  = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new HttpClient(
            baseUrl: 'http://test.example',
            headers: ['Content-Type' => 'application/json'],
            timeout: 5,
            retries: $retries,
            retryDelayMs: $retryDelayMs,
            handler: $stack,
        );
    }

    // ---------------------------------------------------------------------------
    // Seam integration: null handler and callable handler
    // ---------------------------------------------------------------------------

    /** T7 — null handler: HttpClient constructed without $handler uses default Guzzle behavior */
    public function testNullHandlerConstructsWithoutError(): void
    {
        // This just tests that constructing without the 6th param does not throw.
        $client = new HttpClient(
            baseUrl: 'http://localhost',
            headers: [],
            timeout: 5,
        );

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    /** T7 — callable handler injected: MockHandler processes the request, no real network */
    public function testCallableHandlerIsUsedByGuzzle(): void
    {
        $body = json_encode(['id' => 'chatcmpl-1', 'choices' => [['message' => ['content' => 'Hello']]]]);
        $history = [];

        $client = $this->makeClient(
            queue: [new Response(200, [], $body)],
            history: $history,
        );

        $result = $client->post('/chat/completions', ['model' => 'gpt-4o']);

        $this->assertSame('chatcmpl-1', $result['id']);
        $this->assertCount(1, $history, 'Exactly one HTTP request should have been recorded.');
    }

    /** T7 — backward compat: 5-argument callers (no $handler) must not cause errors */
    public function testFiveArgumentConstructorIsBackwardCompatible(): void
    {
        $client = new HttpClient(
            baseUrl: 'http://localhost',
            headers: ['Authorization' => 'Bearer test'],
            timeout: 10,
            retries: 2,
            retryDelayMs: 100,
        );

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    // ---------------------------------------------------------------------------
    // Retry: 429 → 200 (single retry succeeds)
    // ---------------------------------------------------------------------------

    /** T7 — 429 then 200: retry fires once and returns the successful response body */
    public function test429ThenSuccessRetriesOnce(): void
    {
        $successBody = json_encode(['result' => 'ok']);
        $history     = [];

        $client = $this->makeClient(
            queue: [
                new Response(429, [], 'Rate limited'),
                new Response(200, [], $successBody),
            ],
            history: $history,
            retries: 1,
            retryDelayMs: 0,
        );

        $result = $client->post('/completions', ['model' => 'test']);

        $this->assertSame('ok', $result['result']);
        $this->assertCount(2, $history, 'Two requests expected: initial 429 + 1 retry.');
    }

    // ---------------------------------------------------------------------------
    // Retry: 503 exhausted
    // ---------------------------------------------------------------------------

    /** T7 — 503 exhausted: ProviderRequestException thrown with status 503 after all retries */
    public function test503ExhaustedThrowsProviderException(): void
    {
        $history = [];

        $client = $this->makeClient(
            queue: [
                new Response(503, [], 'Service Unavailable'),
                new Response(503, [], 'Service Unavailable'),
                new Response(503, [], 'Service Unavailable'),
            ],
            history: $history,
            retries: 2,
            retryDelayMs: 0,
        );

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionCode(503);

        try {
            $client->post('/completions', ['model' => 'test']);
        } finally {
            $this->assertCount(3, $history, '3 requests expected: 1 initial + 2 retries.');
        }
    }

    // ---------------------------------------------------------------------------
    // Retry: ConnectException → retry → 200
    // ---------------------------------------------------------------------------

    /** T7 — ConnectException triggers retry: succeeds on second attempt */
    public function testConnectExceptionTriggersRetry(): void
    {
        $successBody = json_encode(['answer' => 42]);
        $history     = [];

        $connectException = new ConnectException(
            'cURL error 6: Could not resolve host',
            new Request('POST', 'http://test.example/v1/completions'),
        );

        $client = $this->makeClient(
            queue: [
                $connectException,
                new Response(200, [], $successBody),
            ],
            history: $history,
            retries: 1,
            retryDelayMs: 0,
        );

        $result = $client->post('/completions', ['model' => 'test']);

        $this->assertSame(42, $result['answer']);
        $this->assertCount(2, $history, '2 requests expected: ConnectException + successful retry.');
    }

    // ---------------------------------------------------------------------------
    // Non-retryable 400
    // ---------------------------------------------------------------------------

    /** T7 — 400 does not retry: only one request sent, exception thrown immediately */
    public function testNonRetryable400ThrowsImmediately(): void
    {
        $history = [];

        $client = $this->makeClient(
            queue: [
                new Response(400, [], 'Bad Request'),
                new Response(200, [], '{}'), // Should never be reached
            ],
            history: $history,
            retries: 3,
            retryDelayMs: 0,
        );

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionCode(400);

        try {
            $client->post('/completions', ['model' => 'test']);
        } finally {
            $this->assertCount(1, $history, 'Only 1 request should be sent for a non-retryable 400.');
        }
    }

    // ---------------------------------------------------------------------------
    // Retry count correctness
    // ---------------------------------------------------------------------------

    /** T7 — retry count: exactly retries+1 requests sent when all fail */
    public function testRetryCountIsExactlyRetriesPlusOne(): void
    {
        $history = [];
        $retries = 3;

        $queue = array_fill(0, $retries + 1, new Response(500, [], 'Internal Server Error'));

        $client = $this->makeClient(
            queue: $queue,
            history: $history,
            retries: $retries,
            retryDelayMs: 0,
        );

        $this->expectException(ProviderRequestException::class);

        try {
            $client->post('/completions', ['model' => 'test']);
        } finally {
            $this->assertCount(
                $retries + 1,
                $history,
                "Expected exactly {$retries} retries + 1 initial = " . ($retries + 1) . ' total requests.',
            );
        }
    }

    // ---------------------------------------------------------------------------
    // ProviderRequestException wrapping: correct status codes
    // ---------------------------------------------------------------------------

    /**
     * T7 — ProviderRequestException wraps retryable status codes
     */
    #[DataProvider('retryableStatusCodesProvider')]
    public function testRetryableStatusCodesAreWrappedInProviderRequestException(int $status): void
    {
        $history = [];

        $client = $this->makeClient(
            queue: [new Response($status, [], 'Error')],
            history: $history,
            retries: 0,
            retryDelayMs: 0,
        );

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionCode($status);

        $client->post('/completions', ['model' => 'test']);
    }

    /** @return array<string, array{int}> */
    public static function retryableStatusCodesProvider(): array
    {
        return [
            '429 Too Many Requests'    => [429],
            '500 Internal Server Error' => [500],
            '502 Bad Gateway'           => [502],
            '503 Service Unavailable'   => [503],
        ];
    }

    /**
     * T7 — ProviderRequestException wraps non-retryable 4xx codes without retry
     */
    #[DataProvider('nonRetryableStatusCodesProvider')]
    public function testNonRetryableStatusCodesThrowWithCorrectCode(int $status): void
    {
        $history = [];

        $client = $this->makeClient(
            queue: [
                new Response($status, [], 'Error'),
                new Response(200, [], '{}'), // Must never be reached
            ],
            history: $history,
            retries: 2,
            retryDelayMs: 0,
        );

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionCode($status);

        try {
            $client->post('/completions', ['model' => 'test']);
        } finally {
            $this->assertCount(1, $history, "Status {$status} must not trigger any retry.");
        }
    }

    /** @return array<string, array{int}> */
    public static function nonRetryableStatusCodesProvider(): array
    {
        return [
            '400 Bad Request'  => [400],
            '404 Not Found'    => [404],
            '422 Unprocessable' => [422],
        ];
    }

    // ---------------------------------------------------------------------------
    // Exponential delay: verify retry count with retryDelayMs=0 (no actual sleep)
    //
    // NOTE: PHP's usleep() is a native built-in and cannot be mocked without a
    // test-double wrapper or a runkit/uopz extension. We verify the retry count
    // (behavior proof) rather than the exact microsecond values. The formula
    // retryDelayMs * 2^n is covered by code review; the behavioral contract
    // (retries fire and re-throw after exhaustion) is proven by the count.
    // ---------------------------------------------------------------------------

    /** T7 — exponential delay: retry fires in correct count order (retryDelayMs=0 → no real wait) */
    public function testExponentialDelaySequenceRetryCountIsCorrect(): void
    {
        $history = [];
        $retries = 3;

        // Queue enough 503 to exhaust retries
        $queue = array_fill(0, $retries + 1, new Response(503, [], 'Service Unavailable'));

        $client = $this->makeClient(
            queue: $queue,
            history: $history,
            retries: $retries,
            retryDelayMs: 0, // Formula: 0 * 2^n = 0µs each — no real sleep
        );

        $this->expectException(ProviderRequestException::class);

        try {
            $client->post('/completions', ['test' => 'delay']);
        } finally {
            // 3 retries + 1 initial = 4 total requests proves all retry slots fired
            $this->assertCount($retries + 1, $history);
        }
    }
}
