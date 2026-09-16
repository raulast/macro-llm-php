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
     * Tool output must be representable as MCP text content. Two shapes are rejected with an
     * internal error instead of being placed in `text`:
     *
     * - a string that is not valid UTF-8, and
     * - any value `json_encode()` cannot encode (e.g. a resource).
     *
     * Both used to leak: the first made the whole JSON-RPC response unencodable, which crashed
     * the middleware on a false body; the second put a boolean where the schema promises a
     * string. Report the failure rather than sending data the client cannot trust.
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
        } catch (\Throwable $e) {
            return ['error' => ['code' => -32603, 'message' => $e->getMessage()]];
        }

        if (is_string($result)) {
            // `preg_match` with the /u modifier returns false on malformed UTF-8. Avoids a
            // dependency on ext-mbstring, which is not declared in composer.json.
            if (preg_match('//u', $result) !== 1) {
                return ['error' => [
                    'code'    => -32603,
                    'message' => "Tool '{$name}' returned a string that is not valid UTF-8.",
                ]];
            }

            $text = $result;
        } else {
            // Encode once: this single call decides both encodability and the payload text.
            $encoded = json_encode($result);

            if ($encoded === false) {
                return ['error' => [
                    'code'    => -32603,
                    'message' => "Tool '{$name}' returned a value that cannot be encoded as JSON.",
                ]];
            }

            $text = $encoded;
        }

        return ['result' => ['content' => [
            ['type' => 'text', 'text' => $text],
        ]]];
    }
}
