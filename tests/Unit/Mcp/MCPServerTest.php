<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Mcp;

use MacroLLM\Mcp\MCPServer;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;

/**
 * MCPServer shapes the local ToolRegistry into MCP responses.
 * These are the payload contracts an MCP client sees, so the exact keys and
 * JSON-RPC error codes matter more than internal structure.
 */
final class MCPServerTest extends TestCase
{
    private function tool(string $name, string $description, \Closure $callable): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: $description,
            parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            callable: $callable,
        );
    }

    // ── tools/list ──────────────────────────────────────────────────────────

    public function test_list_tools_returns_an_empty_tools_key_when_nothing_is_registered(): void
    {
        $this->assertSame(['tools' => []], (new MCPServer(new ToolRegistry()))->listTools());
    }

    public function test_list_tools_wraps_every_tool_under_the_tools_key(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('get_weather', 'Get weather', fn() => 'sunny'));
        $registry->register($this->tool('search', 'Search the web', fn() => 'results'));

        $listed = (new MCPServer($registry))->listTools();

        $this->assertArrayHasKey('tools', $listed);
        $this->assertCount(2, $listed['tools']);
    }

    public function test_list_tools_emits_the_mcp_field_names(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('get_weather', 'Get weather', fn() => 'sunny'));

        $entry = (new MCPServer($registry))->listTools()['tools'][0];

        // MCP uses inputSchema, NOT the internal `parameters` name.
        $this->assertSame(['name', 'description', 'inputSchema'], array_keys($entry));
        $this->assertSame('get_weather', $entry['name']);
        $this->assertSame('Get weather', $entry['description']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            $entry['inputSchema'],
        );
    }

    public function test_list_tools_is_a_plain_list_not_keyed_by_tool_name(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('alpha', 'A', fn() => 'a'));
        $registry->register($this->tool('beta', 'B', fn() => 'b'));

        $this->assertSame(
            ['alpha', 'beta'],
            array_map(static fn(array $t): string => $t['name'], (new MCPServer($registry))->listTools()['tools']),
        );
    }

    // ── tools/call — success ────────────────────────────────────────────────

    public function test_call_tool_wraps_a_string_result_as_text_content(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('get_weather', 'Get weather', fn() => 'Sunny, 25C'));

        $result = (new MCPServer($registry))->callTool('get_weather', ['city' => 'Madrid']);

        $this->assertSame(
            ['result' => ['content' => [['type' => 'text', 'text' => 'Sunny, 25C']]]],
            $result,
        );
    }

    public function test_call_tool_json_encodes_a_non_string_result(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('stats', 'Stats', fn() => ['count' => 3, 'ok' => true]));

        $result = (new MCPServer($registry))->callTool('stats', []);

        $this->assertSame(
            '{"count":3,"ok":true}',
            $result['result']['content'][0]['text'],
        );
    }

    public function test_call_tool_passes_the_arguments_array_to_the_callable(): void
    {
        $received = null;
        $registry = new ToolRegistry();
        $registry->register($this->tool('capture', 'Capture', function (array $args) use (&$received): string {
            $received = $args;

            return 'ok';
        }));

        (new MCPServer($registry))->callTool('capture', ['a' => 1, 'b' => 'two']);

        $this->assertSame(['a' => 1, 'b' => 'two'], $received);
    }

    public function test_call_tool_returns_an_internal_error_for_an_invalid_utf8_string_result(): void
    {
        // A tool that returns non-UTF-8 bytes cannot be represented in a JSON-RPC text
        // content block. The server reports that as an internal error instead of passing the
        // raw bytes through, because unencodable bytes later make the whole response
        // unencodable and used to crash the middleware.
        $registry = new ToolRegistry();
        $registry->register($this->tool('bad_utf8', 'Bad UTF-8', fn() => "\xB1\x31"));

        $result = (new MCPServer($registry))->callTool('bad_utf8', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('result', $result);
        $this->assertSame(-32603, $result['error']['code']);
        $this->assertStringContainsString('UTF-8', $result['error']['message']);
    }

    public function test_call_tool_returns_an_internal_error_for_a_non_encodable_result(): void
    {
        // A resource has no JSON representation. Previously json_encode() returned false and
        // that false was placed in `content[0].text`, so the payload carried a boolean where
        // the MCP schema promises a string.
        $registry = new ToolRegistry();
        $registry->register($this->tool('resource_tool', 'Returns a resource', function () {
            return fopen('php://memory', 'r');
        }));

        $result = (new MCPServer($registry))->callTool('resource_tool', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('result', $result);
        $this->assertSame(-32603, $result['error']['code']);
    }

    public function test_call_tool_text_content_is_always_a_string(): void
    {
        // The MCP schema types `content[].text` as a string. This holds for both the
        // pass-through branch and the json_encode branch.
        $registry = new ToolRegistry();
        $registry->register($this->tool('returns_string', 'String', fn() => 'plain'));
        $registry->register($this->tool('returns_array', 'Array', fn() => ['a' => 1]));

        $server = new MCPServer($registry);

        foreach (['returns_string', 'returns_array'] as $name) {
            $this->assertIsString($server->callTool($name, [])['result']['content'][0]['text']);
        }
    }

    // ── tools/call — errors ─────────────────────────────────────────────────

    public function test_call_tool_returns_method_not_found_for_an_unknown_tool(): void
    {
        $result = (new MCPServer(new ToolRegistry()))->callTool('ghost', []);

        $this->assertSame(['error' => ['code' => -32601, 'message' => 'Tool not found: ghost']], $result);
    }

    public function test_call_tool_returns_internal_error_when_the_callable_throws(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('boom', 'Boom', function (): never {
            throw new \RuntimeException('kaboom');
        }));

        $result = (new MCPServer($registry))->callTool('boom', []);

        $this->assertSame(['error' => ['code' => -32603, 'message' => 'kaboom']], $result);
    }

    public function test_call_tool_wraps_error_type_errors_from_a_bad_callable_signature(): void
    {
        $registry = new ToolRegistry();
        // The callable requires an argument the MCP call will not provide.
        $registry->register($this->tool('needs_arg', 'Needs arg', fn(string $required) => $required));

        $result = (new MCPServer($registry))->callTool('needs_arg', []);

        $this->assertSame(-32603, $result['error']['code']);
        // The message is PHP's TypeError text: it names the parameter, not the tool.
        $this->assertStringContainsString('$required', $result['error']['message']);
        $this->assertStringContainsString('must be of type string', $result['error']['message']);
    }

    public function test_call_tool_error_payload_has_exactly_code_and_message(): void
    {
        $result = (new MCPServer(new ToolRegistry()))->callTool('ghost', []);

        $this->assertSame(['code', 'message'], array_keys($result['error']));
    }

    public function test_call_tool_never_returns_both_result_and_error(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->tool('ok', 'Ok', fn() => 'fine'));

        $success = (new MCPServer($registry))->callTool('ok', []);
        $failure = (new MCPServer($registry))->callTool('ghost', []);

        $this->assertArrayHasKey('result', $success);
        $this->assertArrayNotHasKey('error', $success);
        $this->assertArrayHasKey('error', $failure);
        $this->assertArrayNotHasKey('result', $failure);
    }
}
