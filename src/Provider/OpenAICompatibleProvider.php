<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Message\ContentPart;
use MacroLLM\Message\ContentPartType;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Message\Role;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Schema\SchemaNormalizer;
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
            'model'    => $this->mapModel($this->config->defaultModel),
            'messages' => $this->mapMessages($request->messages),
        ];

        if ($request->stream) {
            $payload['stream'] = true;
        }

        if (count($request->tools) > 0) {
            $payload['tools'] = $this->mapTools($request->tools);
        }

        // F-10: Structured output / response format.
        //
        // The schema is normalized HERE, which is what turns the package's provider-interchangeability claim
        // into something true: an unsupported keyword now fails with a path instead of travelling to the
        // provider as a 400 the caller cannot explain.
        if ($request->responseFormat !== null) {
            $payload['response_format'] = $this->mapResponseFormat($request->responseFormat);
        }

        return $payload;
    }

    /**
     * Maps a ResponseFormat to this provider family's wire shape.
     *
     * @return array<string, mixed>
     */
    private function mapResponseFormat(ResponseFormat $format): array
    {
        if ($format->type === 'json_object') {
            return ['type' => 'json_object'];
        }

        // `type === 'json_schema'` implies BOTH a name and a schema: ResponseFormat's constructor is private
        // and jsonSchema() requires them. The invariant is stated rather than assumed, because the previous
        // shape of this method silently emitted a half-formed `response_format` when they were absent.
        if ($format->name === null || $format->schema === null) {
            throw new \LogicException('A json_schema ResponseFormat must carry both a name and a schema.');
        }

        $normalizer = new SchemaNormalizer();
        $schema = $normalizer->normalize($format->schema, SchemaDialect::OpenAi);

        // `strict: true` is a request for the shape strict mode requires, and completing it is a named
        // operation rather than a side effect. It strengthens the contract, so it is not the silent
        // degradation the rest of this engine refuses.
        if ($format->strict) {
            $schema = $normalizer->completeForStrictMode($schema);
        }

        return [
            'type'        => 'json_schema',
            'json_schema' => [
                'name'   => $format->name,
                'schema' => $schema,
                'strict' => $format->strict,
            ],
        ];
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
     * Fetches available models from the provider's /models endpoint.
     * Returns model IDs from the OpenAI-format response (data[].id).
     * Returns [] if the endpoint is unavailable or returns an error.
     *
     * @return string[]
     */
    public function getModels(): array
    {
        $response = $this->fetchRawModels('/models');
        return array_values(array_filter(array_column($response['data'] ?? [], 'id')));
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

        // Multimodal content (ContentPart[])
        if ($message->isMultimodal()) {
            $result['content'] = array_map(
                fn(ContentPart $part) => match ($part->type) {
                    ContentPartType::Text => [
                        'type' => 'text',
                        'text' => $part->value,
                    ],
                    ContentPartType::ImageUrl => [
                        'type'      => 'image_url',
                        'image_url' => ['url' => $part->value, 'detail' => $part->detail],
                    ],
                    ContentPartType::ImageBase64 => [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url'    => 'data:' . $part->mimeType . ';base64,' . $part->value,
                            'detail' => $part->detail,
                        ],
                    ],
                },
                $message->content,
            );

            return $result;
        }

        if ($message->content !== null) {
            $result['content'] = $message->content;
        }

        // Assistant messages with tool calls
        if ($message->role === Role::Assistant && count($message->toolCalls) > 0) {
            $result['tool_calls'] = array_map(
                fn(ToolCall $tc) => [
                    'id'       => $tc->id,
                    'type'     => 'function',
                    'function' => [
                        'name'      => $tc->name,
                        // Cast to object so an empty array serializes as {} not [].
                        // All providers require a JSON object for the arguments field,
                        // even when the tool takes no parameters.
                        'arguments' => json_encode((object) $tc->arguments),
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
