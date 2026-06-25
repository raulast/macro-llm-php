<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Message\ContentPart;
use MacroLLM\Message\ContentPartType;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\Role;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;

class AnthropicProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    public function endpointPath(): string
    {
        return '/messages';
    }

    public function headers(): array
    {
        return [
            'x-api-key' => $this->requireApiKey(),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ];
    }

    public function toPayload(InternalRequest $request): array
    {
        $systemPrompt = '';
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                $systemPrompt .= ($systemPrompt !== '' ? "\n\n" : '') . $message->content;
                continue;
            }

            $messages[] = $this->mapMessage($message);
        }

        $payload = [
            'model' => $this->config->defaultModel,
            'max_tokens' => 4096,
            'messages' => $messages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

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
        $content = null;
        $toolCalls = [];

        foreach ($providerResponse['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content = ($content ?? '') . $block['text'];
            }

            if ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $finishReason = $this->mapStopReason($providerResponse['stop_reason'] ?? 'end_turn');

        $usage = new Usage();
        if (isset($providerResponse['usage'])) {
            $usage = new Usage(
                promptTokens: $providerResponse['usage']['input_tokens'] ?? 0,
                completionTokens: $providerResponse['usage']['output_tokens'] ?? 0,
                totalTokens: ($providerResponse['usage']['input_tokens'] ?? 0) + ($providerResponse['usage']['output_tokens'] ?? 0),
            );
        }

        $knownKeys = ['id', 'type', 'role', 'content', 'model', 'stop_reason', 'stop_sequence', 'usage'];
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

        if ($rawEvent === '') {
            return null;
        }

        // Anthropic sends "event: <type>" followed by "data: <json>"
        // We handle both formats: just data lines or event+data pairs
        if (str_starts_with($rawEvent, 'event: message_stop')) {
            return new StreamChunk(delta: '', index: $index, finished: true);
        }

        if (!str_starts_with($rawEvent, 'data: ')) {
            return null;
        }

        $json = substr($rawEvent, 6);
        $data = json_decode($json, true);

        if ($data === null) {
            return null;
        }

        // content_block_delta event
        if (($data['type'] ?? '') === 'content_block_delta') {
            $text = $data['delta']['text'] ?? '';
            return new StreamChunk(delta: $text, index: $index);
        }

        // message_delta event (contains stop_reason)
        if (($data['type'] ?? '') === 'message_delta') {
            return new StreamChunk(delta: '', index: $index, finished: true);
        }

        return null;
    }

    /**
     * Fetches available models from Anthropic's /models endpoint.
     * Falls back to a curated static list if the endpoint is unavailable.
     *
     * @return string[]
     */
    public function getModels(): array
    {
        $response = $this->fetchRawModels('/models');
        $models = array_values(array_filter(array_column($response['data'] ?? [], 'id')));

        if (!empty($models)) {
            return $models;
        }

        // Fallback static list
        return [
            'claude-opus-4-5',
            'claude-sonnet-4-5',
            'claude-haiku-4-5',
            'claude-3-7-sonnet-20250219',
            'claude-3-5-sonnet-20241022',
            'claude-3-5-sonnet-20240620',
            'claude-3-5-haiku-20241022',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
        ];
    }

    private function mapMessage(InternalMessage $message): array
    {
        $role = match ($message->role) {
            Role::User      => 'user',
            Role::Assistant => 'assistant',
            Role::Tool      => 'user',
            default         => 'user',
        };

        // Tool result
        if ($message->role === Role::Tool) {
            return [
                'role'    => 'user',
                'content' => [[
                    'type'        => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content'     => $message->content ?? '',
                ]],
            ];
        }

        // Assistant with tool calls
        if ($message->role === Role::Assistant && count($message->toolCalls) > 0) {
            $content = [];
            if ($message->content !== null) {
                $content[] = ['type' => 'text', 'text' => $message->content];
            }
            foreach ($message->toolCalls as $tc) {
                $content[] = [
                    'type'  => 'tool_use',
                    'id'    => $tc->id,
                    'name'  => $tc->name,
                    'input' => $tc->arguments,
                ];
            }
            return ['role' => 'assistant', 'content' => $content];
        }

        // Multimodal user message (ContentPart[])
        if ($message->isMultimodal()) {
            $content = array_map(function (ContentPart $part): array {
                return match ($part->type) {
                    ContentPartType::Text => [
                        'type' => 'text',
                        'text' => $part->value,
                    ],
                    ContentPartType::ImageUrl => [
                        'type'   => 'image',
                        'source' => ['type' => 'url', 'url' => $part->value],
                    ],
                    ContentPartType::ImageBase64 => [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $part->mimeType ?? 'image/jpeg',
                            'data'       => $part->value,
                        ],
                    ],
                };
            }, $message->content);

            return ['role' => $role, 'content' => $content];
        }

        return ['role' => $role, 'content' => $message->content ?? ''];
    }

    /**
     * @param ToolDefinition[] $tools
     * @return array<int, array<string, mixed>>
     */
    private function mapTools(array $tools): array
    {
        return array_map(
            fn(ToolDefinition $tool) => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->parameters,
            ],
            $tools,
        );
    }

    private function mapStopReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'stop_sequence' => FinishReason::Stop,
            default => FinishReason::Stop,
        };
    }
}
