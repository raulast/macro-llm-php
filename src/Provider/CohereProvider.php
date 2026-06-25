<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\RankedDocument;
use MacroLLM\Message\RerankingRequest;
use MacroLLM\Message\RerankingResponse;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;

/**
 * Cohere provider — uses native /v2/chat endpoint (not OpenAI-compatible).
 * Supports streaming via SSE (event: text-generation / stream-end).
 */
final class CohereProvider extends AbstractProvider implements
    EmbeddingProviderInterface,
    RerankingProviderInterface
{
    public function name(): string
    {
        return 'cohere';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.cohere.com/v2';
    }

    public function endpointPath(): string
    {
        return '/chat';
    }

    public function toPayload(InternalRequest $request): array
    {
        $payload = [
            'model'    => $this->config->defaultModel,
            'messages' => array_map(
                fn(InternalMessage $m) => [
                    'role'    => $m->role->value === 'assistant' ? 'assistant' : 'user',
                    'content' => $m->content ?? '',
                ],
                array_filter($request->messages, fn($m) => $m->role->value !== 'tool'),
            ),
        ];

        if ($request->stream) {
            $payload['stream'] = true;
        }

        if (count($request->tools) > 0) {
            $payload['tools'] = array_map(
                fn(ToolDefinition $t) => [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $t->name,
                        'description' => $t->description,
                        'parameters'  => $t->parameters,
                    ],
                ],
                $request->tools,
            );
        }

        return $payload;
    }

    public function toResponse(array $providerResponse): InternalResponse
    {
        // Cohere v2: {message: {role, content: [{type: 'text', text: ...}]}, usage: {...}}
        $message = $providerResponse['message'] ?? [];
        $parts   = $message['content'] ?? [];
        $content = null;

        foreach ($parts as $part) {
            if (($part['type'] ?? '') === 'text') {
                $content = ($content ?? '') . $part['text'];
            }
        }

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id:        $tc['id'] ?? uniqid(),
                name:      $tc['function']['name'] ?? '',
                arguments: json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            );
        }

        $usage = new Usage();
        if (isset($providerResponse['usage']['billed_units'])) {
            $b = $providerResponse['usage']['billed_units'];
            $usage = new Usage(
                promptTokens:     $b['input_tokens'] ?? 0,
                completionTokens: $b['output_tokens'] ?? 0,
                totalTokens:      ($b['input_tokens'] ?? 0) + ($b['output_tokens'] ?? 0),
            );
        }

        return new InternalResponse(
            content:      $content,
            finishReason: FinishReason::Stop,
            toolCalls:    $toolCalls,
            usage:        $usage,
        );
    }

    public function parseStreamEvent(string $rawEvent, int $index): ?StreamChunk
    {
        // Cohere SSE events have no "data: " prefix — the line is the raw JSON
        $data = json_decode($rawEvent, true);
        if ($data === null) {
            return null;
        }

        $type = $data['type'] ?? '';

        if ($type === 'content-delta') {
            $delta = $data['delta']['message']['content']['text'] ?? '';
            return new StreamChunk(delta: $delta, index: $index, finished: false);
        }

        if ($type === 'message-end') {
            return new StreamChunk(delta: '', index: $index, finished: true);
        }

        return null;
    }

    public function getModels(): array
    {
        $response = $this->fetchRawModels('/models?endpoint=chat');
        return array_column($response['models'] ?? [], 'name');
    }

    // ── EmbeddingProviderInterface ──────────────────────────────────────────

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $response = (new \MacroLLM\Http\HttpClient(
            $this->baseUrl(),
            $this->headers(),
            $this->config->timeout ?? 30,
        ))->post('/embed', [
            'model'           => $request->model ?? $this->config->defaultModel,
            'texts'           => $request->inputs,
            'input_type'      => 'search_document',
            'embedding_types' => ['float'],
        ]);

        return new EmbeddingResponse(
            $response['embeddings']['float'] ?? [],
            new \MacroLLM\Message\Usage(),
        );
    }

    // ── RerankingProviderInterface ──────────────────────────────────────────

    public function rerank(RerankingRequest $request): RerankingResponse
    {
        $payload = [
            'model'     => $request->model ?? $this->config->defaultModel,
            'query'     => $request->query,
            'documents' => $request->documents,
        ];
        if ($request->limit !== null) {
            $payload['top_n'] = $request->limit;
        }

        $response = (new \MacroLLM\Http\HttpClient(
            $this->baseUrl(),
            $this->headers(),
            $this->config->timeout ?? 30,
        ))->post('/rerank', $payload);

        $results = array_map(
            fn(array $r) => new RankedDocument(
                index:    $r['index'],
                document: $request->documents[$r['index']] ?? '',
                score:    (float) $r['relevance_score'],
            ),
            $response['results'] ?? [],
        );

        return new RerankingResponse($results);
    }
}
