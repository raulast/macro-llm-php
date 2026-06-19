<?php

declare(strict_types=1);

namespace MacroLLM\Mcp;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware that mounts the MCPServer on a configurable path.
 * Handles JSON-RPC 2.0 over HTTP, routing based on the `method` field.
 */
final class MCPServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly MCPServer $server,
        private readonly string $path = '/mcp',
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($request->getUri()->getPath() !== $this->path) {
            return $handler->handle($request);
        }

        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['method'])) {
            return $this->jsonRpcError(-32600, 'Invalid Request', $data['id'] ?? null);
        }

        $id = $data['id'] ?? null;
        $method = $data['method'];
        $params = $data['params'] ?? [];

        $result = match ($method) {
            'tools/list' => $this->server->listTools(),
            'tools/call' => $this->server->callTool(
                $params['name'] ?? '',
                $params['arguments'] ?? [],
            ),
            default => ['error' => ['code' => -32601, 'message' => "Method not found: {$method}"]],
        };

        if (isset($result['error'])) {
            return $this->jsonResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => $result['error'],
            ]);
        }

        return $this->jsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result['result'] ?? $result,
        ]);
    }

    private function jsonResponse(array $data): ResponseInterface
    {
        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data),
        );
    }

    private function jsonRpcError(int $code, string $message, mixed $id): ResponseInterface
    {
        return $this->jsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
