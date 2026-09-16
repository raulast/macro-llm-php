<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Tool;

use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Tool\ToolResult;
use MacroLLM\Tool\ToolStatus;

final class ToolTest extends TestCase
{
    // ── ToolDefinition ──────────────────────────────────────────────────────

    public function test_tool_definition_holds_all_fields(): void
    {
        $callable = fn(array $args): string => 'result';
        $params = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];

        $def = new ToolDefinition(
            name: 'get_weather',
            description: 'Returns current weather.',
            parameters: $params,
            callable: $callable,
        );

        $this->assertSame('get_weather', $def->name);
        $this->assertSame('Returns current weather.', $def->description);
        $this->assertSame($params, $def->parameters);
        // The callable stored is the exact closure reference
        $this->assertSame($callable, $def->callable);
    }

    public function test_tool_definition_callable_is_invocable(): void
    {
        $def = new ToolDefinition(
            name: 'add',
            description: 'Adds two numbers.',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn(array $args): int => $args['a'] + $args['b'],
        );

        $result = ($def->callable)(['a' => 3, 'b' => 4]);
        $this->assertSame(7, $result);
    }

    // ── ToolCall ────────────────────────────────────────────────────────────

    public function test_tool_call_holds_all_fields(): void
    {
        $call = new ToolCall(
            id: 'call_abc123',
            name: 'get_weather',
            arguments: ['city' => 'Buenos Aires'],
        );

        $this->assertSame('call_abc123', $call->id);
        $this->assertSame('get_weather', $call->name);
        $this->assertSame(['city' => 'Buenos Aires'], $call->arguments);
    }

    public function test_tool_call_arguments_can_be_empty(): void
    {
        $call = new ToolCall(id: 'call_x', name: 'no_args_tool', arguments: []);

        $this->assertSame([], $call->arguments);
    }

    // ── ToolStatus ──────────────────────────────────────────────────────────

    public function test_tool_status_ok_value(): void
    {
        $this->assertSame('ok', ToolStatus::Ok->value);
    }

    public function test_tool_status_error_value(): void
    {
        $this->assertSame('error', ToolStatus::Error->value);
    }

    // ── ToolResult ──────────────────────────────────────────────────────────

    public function test_tool_result_ok_factory_sets_status_ok(): void
    {
        $result = ToolResult::ok('call_1', 'get_weather', 'Sunny, 22°C');

        $this->assertSame('call_1', $result->toolCallId);
        $this->assertSame('get_weather', $result->name);
        $this->assertSame('Sunny, 22°C', $result->content);
        $this->assertSame(ToolStatus::Ok, $result->status);
    }

    public function test_tool_result_error_factory_sets_status_error(): void
    {
        $result = ToolResult::error('call_2', 'search_web', 'Connection timeout');

        $this->assertSame('call_2', $result->toolCallId);
        $this->assertSame('search_web', $result->name);
        $this->assertSame('Connection timeout', $result->content);
        $this->assertSame(ToolStatus::Error, $result->status);
    }

    public function test_tool_result_ok_content_can_be_array(): void
    {
        $data = ['temperature' => 22, 'unit' => 'C'];
        $result = ToolResult::ok('call_3', 'get_data', $data);

        $this->assertSame($data, $result->content);
        $this->assertSame(ToolStatus::Ok, $result->status);
    }

    public function test_tool_result_default_status_is_ok(): void
    {
        // Direct constructor — default parameter should be ToolStatus::Ok
        $result = new ToolResult(
            toolCallId: 'call_4',
            name: 'ping',
            content: 'pong',
        );

        $this->assertSame(ToolStatus::Ok, $result->status);
    }
}
