<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\Role;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;

/**
 * Single normalizer shared by the 5 OpenAI-compatible providers.
 * Implements the /v1/chat/completions request/response contract.
 */
class OpenAICompatibleProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'openai-compatible';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function endpointPath(): string
    {
        return '/chat/completions';
    }

    public function toPayload(InternalRequest $request): array
    {
        $payload = [
            'model' => $this->mapModel($this->config->defaultModel),
            'messages' => $this->mapMessages($request->messages),
        ];

        if ($request->stream) {
            $payload['stream'] = true;
        }

        if (count($request->tools) > 0) {
            $payload['tools'] = $this->mapTools($request->tools);
        }

        return $payload;
    }

    public function toResponse(array $providerResponse): InternalResponse
    {
        $choice = $providerResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? null;
        $finishReason = $this->mapFinishReason($choice['finish_reason'] ?? 'stop');

        $toolCalls = [];
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $toolCalls[] = new ToolCall(
                    id: $tc['id'],
                    name: $tc['function']['name'],
                    arguments: json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
                );
            }
        }

        $usage = new Usage();
        if (isset($providerResponse['usage'])) {
            $usage = new Usage(
                promptTokens: $providerResponse['usage']['prompt_tokens'] ?? 0,
                completionTokens: $providerResponse['usage']['completion_tokens'] ?? 0,
                totalTokens: $providerResponse['usage']['total_tokens'] ?? 0,
            );
        }

        // Preserve unmapped fields in extra
        $knownKeys = ['id', 'object', 'created', 'model', 'choices', 'usage', 'system_fingerprint'];
        $extra = array_diff_key($providerResponse, array_flip($knownKeys));

        return new InternalResponse(
            content: $content,
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            usage: $usage,
            extra: $extra,
        );
    }

    public function parseStreamEvent(string $rawEvent, int $index): ?StreamChunk
    {
        $rawEvent = trim($rawEvent);

        if ($rawEvent === '' || $rawEvent === 'data: [DONE]') {
            if ($rawEvent === 'data: [DONE]') {
                return new StreamChunk(delta: '', index: $index, finished: true);
            }
            return null;
        }

        if (!str_starts_with($rawEvent, 'data: ')) {
            return null;
        }

        $json = substr($rawEvent, 6);
        $data = json_decode($json, true);

        if ($data === null) {
            return null;
        }

        $delta = $data['choices'][0]['delta']['content'] ?? '';
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        return new StreamChunk(
            delta: $delta,
            index: $index,
            finished: $finishReason !== null,
        );
    }

    /**
     * Hook for subclasses that must rewrite model names.
     */
    protected function mapModel(string $model): string
    {
        return $model;
    }

    /**
     * Maps InternalMessage[] to OpenAI messages format.
     *
     * @param InternalMessage[] $messages
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $mapped[] = $this->mapMessage($message);
        }

        return $mapped;
    }

    private function mapMessage(InternalMessage $message): array
    {
        $result = [
            'role' => $message->role->value,
        ];

        if ($message->content !== null) {
            $result['content'] = $message->content;
        }

        // Assistant messages with tool calls
        if ($message->role === Role::Assistant && count($message->toolCalls) > 0) {
            $result['tool_calls'] = array_map(
                fn(ToolCall $tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->name,
                        'arguments' => json_encode($tc->arguments),
                    ],
                ],
                $message->toolCalls,
            );
        }

        // Tool result messages
        if ($message->role === Role::Tool) {
            $result['tool_call_id'] = $message->toolCallId;
            if ($message->name !== null) {
                $result['name'] = $message->name;
            }
        }

        return $result;
    }

    /**
     * Maps ToolDefinition[] to OpenAI tools format.
     *
     * @param ToolDefinition[] $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        return array_map(
            fn(ToolDefinition $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->parameters,
                ],
            ],
            $tools,
        );
    }

    private function mapFinishReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Stop,
        };
    }
}
