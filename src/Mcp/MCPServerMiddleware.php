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

    /**
     * Emitted when a payload cannot be JSON-encoded. Plain ASCII, so it always encodes.
     */
    private const UNENCODABLE_FALLBACK_BODY =
        '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Response could not be encoded as JSON."}}';

    /**
     * Serialise a JSON-RPC envelope into a PSR-7 response.
     *
     * json_encode() returns false for unencodable data, and handing that false to a PSR-7
     * Response constructor throws. MCPServer::callTool() rejects unencodable tool output before
     * it reaches here, so this path should be unreachable — but a serialisation boundary must
     * never crash, so it degrades to a well-formed internal-error body instead.
     */
    private function jsonResponse(array $data): ResponseInterface
    {
        $body = json_encode($data);

        if ($body === false) {
            $body = self::UNENCODABLE_FALLBACK_BODY;
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: $body,
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
