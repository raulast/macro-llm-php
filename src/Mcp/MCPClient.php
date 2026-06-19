<?php

declare(strict_types=1);

namespace MacroLLM\Mcp;

use Illuminate\Support\Facades\Http;
use MacroLLM\Exception\MCPConnectionException;
use MacroLLM\Exception\MCPToolCallException;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tool\ToolDefinition;

/**
 * Connects to external MCP servers, discovers their tools,
 * and registers them in the local ToolRegistry under a namespaced name.
 */
final class MCPClient
{
    /** @var array<string, array{url: string, auth: ?string}> */
    private array $servers = [];

    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * Discover and register tools from an MCP server.
     * Tools are registered as "<serverName>/<toolName>" in ToolRegistry.
     *
     * @throws MCPConnectionException
     */
    public function connect(string $serverName, string $url, ?string $auth = null): void
    {
        $this->servers[$serverName] = ['url' => $url, 'auth' => $auth];

        try {
            $http = Http::withHeaders($this->buildHeaders($auth));
            $response = $http->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);
        } catch (\Throwable $e) {
            throw new MCPConnectionException($url, $e->getMessage());
        }

        if ($response->failed()) {
            throw new MCPConnectionException($url, "HTTP {$response->status()}");
        }

        $tools = $response->json('result.tools', []);

        foreach ($tools as $tool) {
            $namespacedName = "{$serverName}/{$tool['name']}";
            $this->tools->register(new ToolDefinition(
                name: $namespacedName,
                description: $tool['description'] ?? '',
                parameters: $tool['inputSchema'] ?? [],
                callable: fn(array $args) => $this->callTool($serverName, $tool['name'], $args),
            ));
        }
    }

    /**
     * @throws MCPConnectionException
     * @throws MCPToolCallException
     */
    private function callTool(string $serverName, string $toolName, array $arguments): mixed
    {
        $server = $this->servers[$serverName];

        try {
            $response = Http::withHeaders($this->buildHeaders($server['auth']))
                ->post($server['url'], [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/call',
                    'params' => ['name' => $toolName, 'arguments' => $arguments],
                ]);
        } catch (\Throwable $e) {
            throw new MCPConnectionException($server['url'], $e->getMessage());
        }

        if ($response->failed() || isset($response->json()['error'])) {
            $error = $response->json('error', []);
            throw new MCPToolCallException(
                $toolName,
                $error['code'] ?? -32603,
                $error['message'] ?? $response->body(),
            );
        }

        return $response->json('result');
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(?string $auth): array
    {
        return $auth ? ['Authorization' => "Bearer {$auth}"] : [];
    }
}
