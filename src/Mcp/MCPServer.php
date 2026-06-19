<?php

declare(strict_types=1);

namespace MacroLLM\Mcp;

use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tool\ToolDefinition;

/**
 * Exposes the local ToolRegistry as an MCP-compliant server.
 * Handles tools/list and tools/call operations.
 */
final class MCPServer
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * Returns MCP-formatted list of all registered tools.
     *
     * @return array{tools: array<int, array{name: string, description: string, inputSchema: array}>}
     */
    public function listTools(): array
    {
        return ['tools' => array_values(array_map(
            fn(ToolDefinition $t) => [
                'name' => $t->name,
                'description' => $t->description,
                'inputSchema' => $t->parameters,
            ],
            $this->tools->all(),
        ))];
    }

    /**
     * Resolves and executes a tool; returns MCP result or MCP error.
     *
     * @return array{result?: array, error?: array{code: int, message: string}}
     */
    public function callTool(string $name, array $arguments): array
    {
        if (!$this->tools->has($name)) {
            return ['error' => ['code' => -32601, 'message' => "Tool not found: {$name}"]];
        }

        $tool = $this->tools->get($name);

        try {
            $result = ($tool->callable)($arguments);

            return ['result' => ['content' => [
                ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result)],
            ]]];
        } catch (\Throwable $e) {
            return ['error' => ['code' => -32603, 'message' => $e->getMessage()]];
        }
    }
}
