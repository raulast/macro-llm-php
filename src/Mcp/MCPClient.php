<?php

declare(strict_types=1);

namespace MacroLLM\Mcp;

use MacroLLM\Exception\MCPConnectionException;
use MacroLLM\Exception\MCPToolCallException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tool\ToolDefinition;

/**
 * Connects to external MCP servers, discovers their tools,
 * and registers them in the local ToolRegistry under a namespaced name.
 *
 * HTTP traffic goes through {@see HttpClient} and no framework facade, so the class works in
 * a non-Laravel install. The optional handler-stack factory is an @internal test seam and is
 * never set by production callers.
 *
 * Both request sites pass their transport values explicitly rather than inheriting them from an
 * HttpClient default: the 30-second total request timeout, `retries: 0`, and a 10-second TCP
 * connect bound (MCPC-9, MCPC-10, MCPC-11). The connect bound restores the parity the previous
 * transport provided; its 10-second value is the recorded parity target, not a measurement. The
 * bound is fixed and non-configurable in this change, and MCP remains the only consumer that
 * opts into one.
 */
final class MCPClient
{
    private const TIMEOUT = 30;
    /**
     * MCP's TCP connect bound. `10` is the parity target recorded by MCPC-11 (documented
     * history, not a measurement) and is passed explicitly at both call sites.
     */
    private const CONNECT_TIMEOUT = 10;

    /** @var array<string, array{url: string, auth: ?string}> */
    private array $servers = [];

    public function __construct(
        private readonly ToolRegistry $tools,
        /**
         * Optional Guzzle handler stack factory for testing.
         * Invoked before each HTTP request and its return value is passed as the
         * handler to HttpClient. Production callers must never set this.
         *
         * @internal
         * @var (\Closure(): callable)|null
         */
        private readonly ?\Closure $httpHandlerFactory = null,
    ) {
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

        $handler = $this->resolveHandler();

        try {
            // The base URL and the request path are both the absolute server URL: per RFC 3986
            // §5.2.2 an absolute reference resolves to itself, whereas post('') would resolve to
            // the base URL and append a trailing slash (MCPC-3).
            // `retries` is the literal 0, never HttpClient's default, so a future default
            // change cannot silently arm retries for MCP traffic (MCPC-9).
            // `connectTimeout` is likewise passed explicitly, never left to HttpClient's default,
            // so MCP keeps its 10-second TCP connect bound whatever that default becomes (MCPC-11).
            $data = (new HttpClient(
                baseUrl: $url,
                headers: $this->buildHeaders($auth),
                timeout: self::TIMEOUT,
                retries: 0,
                retryDelayMs: 500,
                handler: $handler,
                connectTimeout: self::CONNECT_TIMEOUT,
            ))->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ]);
        } catch (ProviderRequestException $e) {
            // An HTTP failure — this clause MUST precede the \Throwable clause below, because
            // ProviderRequestException is itself a \RuntimeException (MCPC-6).
            throw new MCPConnectionException($url, "HTTP {$e->statusCode}");
        } catch (\Throwable $e) {
            // Everything else, a transport \RuntimeException included: one wrap, one message (MCPC-5).
            throw new MCPConnectionException($url, $e->getMessage());
        }

        foreach ($data['result']['tools'] ?? [] as $tool) {
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

        $handler = $this->resolveHandler();

        try {
            // Absolute URL as both base and path: post('') would append a trailing slash (MCPC-3).
            // `retries` is the literal 0: a retried tools/call can execute a non-idempotent
            // action twice, and the default must not be able to arm retries for MCP (MCPC-9).
            // Same explicit bound as the discovery site: the two sites are edited independently
            // and MCPC-11 requires both (MCPC-10).
            $data = (new HttpClient(
                baseUrl: $server['url'],
                headers: $this->buildHeaders($server['auth']),
                timeout: self::TIMEOUT,
                retries: 0,
                retryDelayMs: 500,
                handler: $handler,
                connectTimeout: self::CONNECT_TIMEOUT,
            ))->post($server['url'], [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $toolName, 'arguments' => $arguments],
            ]);
        } catch (ProviderRequestException $e) {
            // HTTP failure first (MCPC-6). A failed response may still carry a structured
            // JSON-RPC error body, so re-decode it instead of losing the detail (MCPC-8).
            $error = json_decode($e->responseBody, true)['error'] ?? null;
            throw self::toolCallFailure($toolName, is_array($error) ? $error : null, $e->responseBody);
        } catch (\Throwable $e) {
            // Everything else, a transport \RuntimeException included: one wrap, one message (MCPC-6).
            throw new MCPConnectionException($server['url'], $e->getMessage());
        }

        // Outside the try: MCPToolCallException is itself a \RuntimeException, so classifying
        // it inside would re-wrap the tool-call failure as a connection failure (MCPC-6).
        if (isset($data['error'])) {
            throw self::toolCallFailure(
                $toolName,
                is_array($data['error']) ? $data['error'] : null,
                (string) json_encode($data),
            );
        }

        return $data['result'] ?? null;   // MCPC-7: never `?? []`
    }

    /**
     * Maps a JSON-RPC error body onto MCPToolCallException, falling back to -32603 only when
     * the body is not JSON or carries no integer `error.code` (MCPC-8).
     *
     * A `message` that is missing, non-string, **or empty** is not usable text, so the raw body is
     * used instead of an empty exception message. Failure wording is non-contractual (MCPC-5).
     *
     * @param array<string, mixed>|null $error
     */
    private static function toolCallFailure(string $toolName, ?array $error, string $fallbackMessage): MCPToolCallException
    {
        if ($error === null || ! is_int($error['code'] ?? null)) {
            return new MCPToolCallException($toolName, -32603, $fallbackMessage);
        }

        $message = $error['message'] ?? null;

        return new MCPToolCallException(
            $toolName,
            $error['code'],
            is_string($message) && $message !== '' ? $message : $fallbackMessage,
        );
    }

    /**
     * Resolves the optional @internal test handler (MCPC-12). The factory is invoked here;
     * the handler it returns — not the closure — is what HttpClient receives.
     */
    private function resolveHandler(): ?callable
    {
        return $this->httpHandlerFactory !== null ? ($this->httpHandlerFactory)() : null;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(?string $auth): array
    {
        return $auth ? ['Authorization' => "Bearer {$auth}"] : [];
    }
}
