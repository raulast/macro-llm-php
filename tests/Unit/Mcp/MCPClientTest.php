<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Mcp;

use ArrayObject;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Exception\MCPConnectionException;
use MacroLLM\Exception\MCPToolCallException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Mcp\MCPClient;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MCPClient's first suite: the pre-migration contract plus the seam that makes it
 * observable. Offline by construction — every request is served by an injected Guzzle
 * handler stack, so no MCP server is contacted and no network is used.
 *
 * Two invariants are asserted wherever a request happens: the *resolved* request URI
 * (recorded by Middleware::history after Guzzle resolves against base_uri, which is what
 * makes MCPC-3 detectable) and the request count (the behavioural proxy for MCPC-9's
 * explicit `retries: 0`; `retries` is not a Guzzle request option, so the literal `0` at
 * both construction sites is pinned by review as well).
 */
final class MCPClientTest extends TestCase
{
    private const URL = 'http://localhost:3001';

    // ── Fixtures and helpers ────────────────────────────────────────────────

    /**
     * @param  list<Response|\Throwable>  $queue  Responses/exceptions served in order
     * @return array{MCPClient, ArrayObject, ToolRegistry}
     */
    private function makeClient(array $queue, ?ToolRegistry $tools = null): array
    {
        $tools ??= new ToolRegistry();
        $history = new ArrayObject();
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return [new MCPClient($tools, fn() => $stack), $history, $tools];
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $tools */
    private function discovery(array $tools): Response
    {
        return new Response(200, [], $this->json(['result' => ['tools' => $tools]]));
    }

    private function toolResult(array $result): Response
    {
        return new Response(200, [], $this->json(['result' => $result]));
    }

    /** @return array<string, mixed> */
    private function readFileTool(): array
    {
        return [
            'name' => 'read_file',
            'description' => 'Read a file',
            'inputSchema' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
        ];
    }

    /** @return array<string, mixed> */
    private function requestBody(ArrayObject $history, int $index): array
    {
        return json_decode((string) $history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Invokes the callable registered for the `filesystem/read_file` tool. @param array<string, mixed> $arguments */
    private function invokeReadFile(ToolRegistry $tools, array $arguments): mixed
    {
        return ($tools->get('filesystem/read_file')->callable)($arguments);
    }

    // ── Phase 1 — the testability seam (new code: RED first) ─────────────────

    /**
     * MCPC-12. The seam is a *factory*: the closure is invoked and the handler it
     * returns is what HttpClient receives. Passing `fn() => $stack` straight through
     * would make Guzzle invoke the closure as the handler and raise
     * `Return value must be of type ResponseInterface, GuzzleHttp\HandlerStack returned`.
     */
    public function test_injected_handler_factory_is_resolved_to_a_handler(): void
    {
        [$client, $history, $tools] = $this->makeClient([
            new Response(200, [], $this->json(['result' => ['tools' => [
                ['name' => 'read_file', 'description' => 'Read a file', 'inputSchema' => ['type' => 'object']],
            ]]])),
            new Response(200, [], $this->json(['result' => ['content' => [['type' => 'text', 'text' => 'ok']]]])),
        ]);

        $client->connect('filesystem', 'http://localhost:3001');
        $this->invokeReadFile($tools, ['path' => '/etc/hosts']);

        // The seam is invoked per request, at both call sites: two requests, two queued responses.
        $this->assertCount(2, $history, 'the queued responses must be served by the injected stack');
    }

    /** MCPC-12. The seam is unset in production; the documented one-argument call keeps working. */
    public function test_seam_defaults_to_null_and_uses_the_real_transport(): void
    {
        $constructor = new \ReflectionMethod(MCPClient::class, '__construct');

        $this->assertNull($constructor->getParameters()[1]->getDefaultValue());
        $this->assertInstanceOf(MCPClient::class, new MCPClient(new ToolRegistry()));
    }

    // ── MCPC-1 — class and construction contract ────────────────────────────

    /** MCPC-1. The documented consumer usage `new MCPClient($llm->tools())` is unchanged. */
    public function test_single_argument_construction_still_works(): void
    {
        $this->assertInstanceOf(MCPClient::class, new MCPClient(new ToolRegistry()));
    }

    /** MCPC-1. Final class; the only required dependency is the ToolRegistry. */
    public function test_class_is_final_and_constructor_requires_only_a_tool_registry(): void
    {
        $class = new \ReflectionClass(MCPClient::class);
        $parameters = $class->getConstructor()->getParameters();

        $this->assertTrue($class->isFinal());
        $this->assertCount(2, $parameters);
        $this->assertSame(ToolRegistry::class, $parameters[0]->getType()->getName());
        // No Config, no container, no illuminate class: the added seam is a plain optional closure.
        $this->assertSame(\Closure::class, $parameters[1]->getType()->getName());
        $this->assertTrue($parameters[1]->isOptional());
        $this->assertNull($parameters[1]->getDefaultValue());
    }

    // ── MCPC-2 — discovery and registration ─────────────────────────────────

    /** MCPC-2. Namespaced registration; a server with no tools registers nothing and does not throw. */
    public function test_discovered_tools_are_registered_under_namespaced_names(): void
    {
        [$client, , $tools] = $this->makeClient([$this->discovery([$this->readFileTool()])]);
        $client->connect('filesystem', self::URL);

        $this->assertTrue($tools->has('filesystem/read_file'));
        $this->assertFalse($tools->has('read_file'), 'tools are always namespaced');

        [$emptyClient, , $emptyTools] = $this->makeClient([$this->discovery([])]);
        $emptyClient->connect('empty', self::URL);

        $this->assertSame([], $emptyTools->all());
    }

    /** MCPC-2. Server metadata maps onto the registered definition, with '' and [] defaults. */
    public function test_server_metadata_maps_to_registered_definition(): void
    {
        [$client, , $tools] = $this->makeClient([$this->discovery([
            $this->readFileTool(),
            ['name' => 'bare'],
        ])]);
        $client->connect('filesystem', self::URL);

        $this->assertSame('Read a file', $tools->get('filesystem/read_file')->description);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            $tools->get('filesystem/read_file')->parameters,
        );
        $this->assertSame('', $tools->get('filesystem/bare')->description);
        $this->assertSame([], $tools->get('filesystem/bare')->parameters);
    }

    /** MCPC-2. Registration is namespace-isolated: two servers register side by side. */
    public function test_two_servers_register_side_by_side(): void
    {
        [$client, , $tools] = $this->makeClient([
            $this->discovery([$this->readFileTool()]),
            $this->discovery([$this->readFileTool()]),
        ]);

        $client->connect('filesystem', self::URL);
        $client->connect('other', 'http://localhost:3002');

        $this->assertTrue($tools->has('filesystem/read_file'));
        $this->assertTrue($tools->has('other/read_file'));
        $this->assertCount(2, $tools->all());
    }

    // ── MCPC-4 + MCPC-2 — wire contract and callable forwarding ─────────────

    /**
     * MCPC-4 + MCPC-2. Exact envelopes, exact arguments, exact headers, and forwarding to
     * the remote (unprefixed) tool name through the URL and auth captured at connect() time.
     *
     * @return array<string, array{?string, string}>
     */
    public static function wireContractProvider(): array
    {
        return [
            'bearer token from connect()' => ['my-token', 'Bearer my-token'],
            'no auth token' => [null, ''],
        ];
    }

    #[DataProvider('wireContractProvider')]
    public function test_wire_contract_and_callable_forwarding_are_exact(?string $auth, string $authorization): void
    {
        $arguments = ['path' => '/etc/hosts', 'limit' => 5];
        [$client, $history, $tools] = $this->makeClient([
            $this->discovery([$this->readFileTool()]),
            $this->toolResult(['content' => [['type' => 'text', 'text' => 'ok']]]),
        ]);

        $client->connect('filesystem', self::URL, $auth);
        $this->invokeReadFile($tools, $arguments);

        $this->assertCount(2, $history);

        $discovery = $history[0]['request'];
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            $this->requestBody($history, 0),
        );
        $this->assertSame('application/json', $discovery->getHeaderLine('Content-Type'));
        $this->assertSame($authorization, $discovery->getHeaderLine('Authorization'));
        $this->assertFalse($discovery->hasHeader('Accept'), 'no Accept header is introduced');

        $call = $history[1]['request'];
        $body = $this->requestBody($history, 1);
        $this->assertSame(1, $body['id']);
        $this->assertSame('tools/call', $body['method']);
        $this->assertSame('read_file', $body['params']['name'], 'the remote name, unprefixed');
        $this->assertSame($arguments, $body['params']['arguments'], 'passed through verbatim');
        $this->assertSame($authorization, $call->getHeaderLine('Authorization'));
        $this->assertFalse($call->hasHeader('Accept'));
        $this->assertSame(self::URL, $call->getUri()->__toString(), 'targets the connect()-time URL');
    }

    // ── MCPC-3 + MCPC-10 — resolved request URI and timeout (LOAD-BEARING) ──

    /**
     * MCPC-3 (load-bearing) + MCPC-10. Both requests resolve to exactly the URL passed to
     * connect() — no trailing slash, nothing re-encoded. Asserted on the *resolved* URI.
     *
     * @return array<string, array{string}>
     */
    public static function absoluteUrlProvider(): array
    {
        return [
            'origin only' => ['http://localhost:3001'],
            'path mounted' => ['http://localhost:3001/mcp'],
        ];
    }

    #[DataProvider('absoluteUrlProvider')]
    public function test_resolved_request_uri_is_verbatim_for_both_requests(string $url): void
    {
        [$client, $history, $tools] = $this->makeClient([
            $this->discovery([$this->readFileTool()]),
            $this->toolResult(['ok' => true]),
        ]);

        $client->connect('filesystem', $url);
        $this->invokeReadFile($tools, ['path' => '/etc/hosts']);

        $this->assertCount(2, $history);
        $this->assertSame($url, $history[0]['request']->getUri()->__toString());
        $this->assertSame($url, $history[1]['request']->getUri()->__toString());
        // MCPC-10: the 30-second total request timeout reaches Guzzle's request options.
        $this->assertSame(30, $history[0]['options']['timeout'] ?? null);
    }

    // ── MCPC-5 + MCPC-6 + MCPC-9 — exception contract and classification ─────

    /** MCPC-5 + MCPC-6 + MCPC-9. A failing discovery status is a connection failure, and only one request is sent. */
    public function test_failing_discovery_status_throws_connection_exception(): void
    {
        [$client, $history] = $this->makeClient([new Response(500, [], 'Internal Server Error')]);

        try {
            $client->connect('filesystem', self::URL);
            $this->fail('connect() must fail on a failing HTTP status');
        } catch (MCPConnectionException $e) {
            // ProviderRequestException never escapes (MCPC-5): the HTTP clause handles it first (MCPC-6).
            $this->assertSame(self::URL, $e->url);
            $this->assertStringContainsString('HTTP 500', $e->detail);
        }

        $this->assertCount(1, $history, 'exactly one request: no retry follows a failure');
    }

    /** MCPC-5 + MCPC-9. An unreachable server is a connection failure carrying the requested URL. */
    public function test_unreachable_server_throws_connection_exception_with_the_requested_url(): void
    {
        $failure = new ConnectException('Connection refused', new Request('POST', self::URL));
        [$client, $history] = $this->makeClient([$failure]);

        try {
            $client->connect('filesystem', self::URL);
            $this->fail('connect() must fail when the transport cannot connect');
        } catch (MCPConnectionException $e) {
            // The transport's own \RuntimeException never escapes the class (MCPC-5).
            $this->assertSame(self::URL, $e->url);
            $this->assertStringContainsString('Connection refused', $e->detail);
        }

        $this->assertCount(1, $history, 'exactly one request: no retry follows a failure');
    }

    // ── MCPC-8 — structured JSON-RPC detail preservation (LOAD-BEARING) ─────

    /**
     * MCPC-8 (load-bearing) + MCPC-5/6/9. The structured JSON-RPC error survives a failing
     * HTTP status, and the -32603 fallback is used only for a non-JSON body.
     *
     * @return array<string, array{Response, int, string}>
     */
    public static function structuredErrorProvider(): array
    {
        return [
            'HTTP failure with a structured body' => [
                new Response(500, [], '{"error":{"code":-32000,"message":"boom"}}'), -32000, 'boom',
            ],
            'HTTP failure with a non-JSON body' => [
                new Response(502, [], 'Bad Gateway'), -32603, 'Bad Gateway',
            ],
            'HTTP 200 carrying an error member' => [
                new Response(200, [], '{"result":null,"error":{"code":-32602,"message":"Invalid params"}}'),
                -32602, 'Invalid params',
            ],
        ];
    }

    #[DataProvider('structuredErrorProvider')]
    public function test_structured_error_detail_is_preserved(Response $failure, int $code, string $message): void
    {
        [$client, $history, $tools] = $this->makeClient([$this->discovery([$this->readFileTool()]), $failure]);
        $client->connect('filesystem', self::URL);

        try {
            $this->invokeReadFile($tools, ['path' => '/etc/hosts']);
            $this->fail('a JSON-RPC error must surface as MCPToolCallException');
        } catch (MCPToolCallException $e) {
            $this->assertNotInstanceOf(MCPConnectionException::class, $e);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($message, $e->errorMessage);
            $this->assertSame($code, $e->getCode());
            $this->assertSame('read_file', $e->toolName, 'the remote name, unprefixed');
        }

        $this->assertCount(2, $history, 'discovery plus exactly one call: no retry follows a failure');
    }

    // ── MCPC-7 — result pass-through ────────────────────────────────────────

    /** MCPC-7. The decoded `result` is returned as-is; `content[].text` is never unwrapped. */
    public function test_decoded_result_is_returned_unchanged(): void
    {
        $result = ['content' => [['type' => 'text', 'text' => 'hi']]];
        [$client, $history, $tools] = $this->makeClient([$this->discovery([$this->readFileTool()]), $this->toolResult($result)]);

        $client->connect('filesystem', self::URL);
        $returned = $this->invokeReadFile($tools, ['path' => '/etc/hosts']);

        $this->assertSame($result, $returned);
        $this->assertCount(2, $history);
    }

    /** MCPC-7. A response without a `result` member yields null, never an empty array. */
    public function test_missing_result_returns_null_and_not_an_empty_array(): void
    {
        [$client, , $tools] = $this->makeClient([
            $this->discovery([$this->readFileTool()]),
            new Response(200, [], '{"jsonrpc":"2.0","id":1}'),
        ]);

        $client->connect('filesystem', self::URL);
        $returned = $this->invokeReadFile($tools, ['path' => '/etc/hosts']);

        $this->assertNull($returned);
        $this->assertNotSame([], $returned);
    }

    // ── MCPC-13 + MCPC-11 — boundary and freeze pins ────────────────────────

    /** MCPC-13. The class is illuminate-free: all traffic goes through MacroLLM\Http\HttpClient. */
    public function test_client_has_no_illuminate_reference(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Mcp/MCPClient.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsStringIgnoringCase('illuminate', $source);
    }

    /**
     * MCPC-11. HttpClient's constructor contract is frozen: no parameter was added to
     * compensate for the lost 10-second connect bound. Kept because HttpClientTest.php
     * pins behaviour only (null handler, five-argument call, injected handler) and never
     * reflects on the constructor shape.
     */
    public function test_http_client_constructor_is_not_extended(): void
    {
        $parameters = (new \ReflectionClass(HttpClient::class))->getConstructor()->getParameters();

        $this->assertCount(6, $parameters);
        $this->assertNotContains(
            'connect_timeout',
            array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
        );
    }
}
