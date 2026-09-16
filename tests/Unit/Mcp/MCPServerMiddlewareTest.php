<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Mcp;

use InvalidArgumentException;
use MacroLLM\Mcp\MCPServer;
use MacroLLM\Mcp\MCPServerMiddleware;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that mounts MCPServer on a path. Covers the JSON-RPC routing
 * contract and the pass-through behavior for unrelated paths.
 */
final class MCPServerMiddlewareTest extends TestCase
{
    /** Records every request it receives and answers with a distinct passthrough response. */
    private function recordingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            /** @var ServerRequestInterface[] */
            public array $handled = [];

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handled[] = $request;

                return new Response(418, ['X-Passthrough' => 'yes'], 'passthrough');
            }
        };
    }

    private function tool(string $name, \Closure $callable): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: "Tool {$name}",
            parameters: ['type' => 'object', 'properties' => []],
            callable: $callable,
        );
    }

    private function serverWithTools(ToolDefinition ...$tools): MCPServer
    {
        $registry = new ToolRegistry();

        foreach ($tools as $tool) {
            $registry->register($tool);
        }

        return new MCPServer($registry);
    }

    private function request(string $path, array|string|null $body, string $method = 'POST'): ServerRequest
    {
        $payload = match (true) {
            $body === null  => '',
            is_string($body) => $body,
            default         => json_encode($body),
        };

        return new ServerRequest($method, "http://localhost{$path}", ['Content-Type' => 'application/json'], $payload);
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    // ── Pass-through ────────────────────────────────────────────────────────

    public function test_non_matching_path_is_delegated_to_the_handler(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $response = $middleware->process($this->request('/other', null), $handler);

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('passthrough', (string) $response->getBody());
        $this->assertCount(1, $handler->handled);
    }

    public function test_the_handler_receives_the_original_request_unchanged(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools());
        $request = $this->request('/not-mcp', ['anything' => true]);

        $middleware->process($request, $handler);

        $this->assertSame($request, $handler->handled[0]);
    }

    public function test_a_trailing_slash_does_not_match_the_mount_path(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        // Path comparison is exact string equality — '/mcp/' is not '/mcp'.
        $middleware->process($this->request('/mcp/', null), $handler);

        $this->assertCount(1, $handler->handled);
    }

    public function test_mount_path_is_configurable(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools(), '/custom-mcp');

        $response = $middleware->process($this->request('/custom-mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ]), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $handler->handled);
    }

    public function test_default_mount_path_is_no_longer_served_when_a_custom_one_is_configured(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools(), '/custom-mcp');

        $middleware->process($this->request('/mcp', null), $handler);

        $this->assertCount(1, $handler->handled);
    }

    // ── tools/list ──────────────────────────────────────────────────────────

    public function test_tools_list_returns_a_json_rpc_result(): void
    {
        $handler = $this->recordingHandler();
        $middleware = new MCPServerMiddleware($this->serverWithTools($this->tool('alpha', fn() => 'a')));

        $response = $middleware->process($this->request('/mcp', [
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list',
        ]), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $decoded = $this->decode($response);

        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(7, $decoded['id']);
        $this->assertSame('alpha', $decoded['result']['tools'][0]['name']);
        $this->assertArrayNotHasKey('error', $decoded);
    }

    public function test_tools_list_with_no_tools_returns_an_empty_list(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
            $this->recordingHandler(),
        ));

        $this->assertSame([], $decoded['result']['tools']);
    }

    // ── tools/call ──────────────────────────────────────────────────────────

    public function test_tools_call_returns_the_tool_text_content(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools(
            $this->tool('echo', fn(array $args) => $args['value'] ?? ''),
        ));

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', [
                'jsonrpc' => '2.0',
                'id'      => 'req-1',
                'method'  => 'tools/call',
                'params'  => ['name' => 'echo', 'arguments' => ['value' => 'hello']],
            ]),
            $this->recordingHandler(),
        ));

        $this->assertSame('req-1', $decoded['id']);
        $this->assertSame('hello', $decoded['result']['content'][0]['text']);
        $this->assertSame('text', $decoded['result']['content'][0]['type']);
    }

    public function test_tools_call_for_an_unknown_tool_returns_a_json_rpc_error(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', [
                'jsonrpc' => '2.0',
                'id'      => 2,
                'method'  => 'tools/call',
                'params'  => ['name' => 'ghost'],
            ]),
            $this->recordingHandler(),
        ));

        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertSame('Tool not found: ghost', $decoded['error']['message']);
        $this->assertArrayNotHasKey('result', $decoded);
    }

    public function test_tools_call_without_params_reports_an_empty_tool_name(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call']),
            $this->recordingHandler(),
        ));

        // params['name'] defaults to '', so the failure is a lookup miss, not a validation error.
        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertSame('Tool not found: ', $decoded['error']['message']);
    }

    public function test_tools_call_uses_an_empty_argument_array_when_arguments_are_omitted(): void
    {
        $received = null;
        $middleware = new MCPServerMiddleware($this->serverWithTools(
            $this->tool('capture', function (array $args) use (&$received): string {
                $received = $args;

                return 'ok';
            }),
        ));

        $middleware->process(
            $this->request('/mcp', [
                'jsonrpc' => '2.0',
                'id'      => 4,
                'method'  => 'tools/call',
                'params'  => ['name' => 'capture'],
            ]),
            $this->recordingHandler(),
        );

        $this->assertSame([], $received);
    }

    // ── Unknown method ──────────────────────────────────────────────────────

    public function test_unknown_method_returns_method_not_found(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/list']),
            $this->recordingHandler(),
        ));

        $this->assertSame(-32601, $decoded['error']['code']);
        $this->assertSame('Method not found: prompts/list', $decoded['error']['message']);
    }

    // ── Invalid request ─────────────────────────────────────────────────────

    public function test_malformed_json_returns_invalid_request_with_a_null_id(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $response = $middleware->process($this->request('/mcp', '{not json'), $this->recordingHandler());
        $decoded = $this->decode($response);

        // Errors are still HTTP 200 — JSON-RPC carries the failure.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertSame('Invalid Request', $decoded['error']['message']);
        $this->assertNull($decoded['id']);
    }

    public function test_a_json_scalar_body_is_rejected_as_invalid_request(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', '"just a string"'),
            $this->recordingHandler(),
        ));

        $this->assertSame(-32600, $decoded['error']['code']);
    }

    public function test_an_empty_body_is_rejected_as_invalid_request(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', null),
            $this->recordingHandler(),
        ));

        $this->assertSame(-32600, $decoded['error']['code']);
    }

    public function test_a_body_without_a_method_field_is_rejected_as_invalid_request(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', ['jsonrpc' => '2.0', 'id' => 5]),
            $this->recordingHandler(),
        ));

        $this->assertSame(-32600, $decoded['error']['code']);
        $this->assertSame(5, $decoded['id']);
    }

    // ── id echoing ──────────────────────────────────────────────────────────

    public function test_an_absent_id_is_echoed_as_null(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        $decoded = $this->decode($middleware->process(
            $this->request('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list']),
            $this->recordingHandler(),
        ));

        $this->assertNull($decoded['id']);
    }

    public function test_string_and_numeric_ids_are_echoed_unchanged(): void
    {
        $middleware = new MCPServerMiddleware($this->serverWithTools());

        foreach ([0, 42, 'abc-123'] as $id) {
            $decoded = $this->decode($middleware->process(
                $this->request('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list']),
                $this->recordingHandler(),
            ));

            $this->assertSame($id, $decoded['id']);
        }
    }

    // ── Documented latent bug ───────────────────────────────────────────────

    public function test_invalid_utf8_in_a_tool_string_result_aborts_the_response(): void
    {
        // KNOWN BUG (pinned, not endorsed). MCPServer puts raw invalid-UTF-8 bytes into
        // `result.content[0].text`, then jsonResponse() calls json_encode() on the whole
        // payload. json_encode() returns false, and Nyholm's Response constructor throws
        // on a false body — an uncaught InvalidArgumentException instead of a JSON-RPC error.
        //
        // When this is fixed (encode with JSON_INVALID_UTF8_SUBSTITUTE, or guard the false),
        // this test must be replaced by one asserting a well-formed JSON-RPC response.
        $middleware = new MCPServerMiddleware($this->serverWithTools(
            $this->tool('bad_utf8', fn() => "\xB1\x31"),
        ));

        $this->expectException(InvalidArgumentException::class);

        $middleware->process(
            $this->request('/mcp', [
                'jsonrpc' => '2.0',
                'id'      => 6,
                'method'  => 'tools/call',
                'params'  => ['name' => 'bad_utf8'],
            ]),
            $this->recordingHandler(),
        );
    }
}
