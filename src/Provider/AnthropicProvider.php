<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Message\ContentPart;
use MacroLLM\Message\ContentPartType;
use MacroLLM\Exception\StructuredOutputUnsupportedException;
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
    /**
     * The tool name this provider forces when a schema is requested.
     *
     * **Reserved.** A caller tool with this name would collide with the forced one, so the request is refused
     * rather than letting one silently shadow the other.
     */
    private const STRUCTURED_OUTPUT_TOOL = 'structured_output';

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

        if ($request->responseFormat !== null) {
            $this->applyStructuredOutput($payload, $request);
        }

        return $payload;
    }

    /**
     * Anthropic has no `response_format`, so a schema is enforced by forcing a single tool call: the schema becomes
     * the tool's `input_schema` and `tool_choice` pins that tool. The answer arrives as the tool's input, which
     * {@see toResponse()} unwraps back into content, so a caller sees the same shape as on every other provider.
     *
     * The schema is NOT run through the dialect engine: Anthropic consumes it as a tool `input_schema`, which is
     * plain JSON Schema, and this package has not verified Anthropic's subset. Passing it through makes no claim;
     * filtering it against a guessed list would make a false one. Verifying that subset is a recorded follow-up.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyStructuredOutput(array &$payload, InternalRequest $request): void
    {
        $format = $request->responseFormat;

        if ($format->type !== 'json_schema') {
            throw StructuredOutputUnsupportedException::noSuchMode(
                'anthropic',
                $format->type,
                'tool-forcing needs a schema to enforce, and Anthropic documents no schema-less JSON mode',
            );
        }

        if ($format->schema === null) {
            throw new \LogicException('A json_schema ResponseFormat must carry a schema.');
        }

        foreach ($request->tools as $tool) {
            if ($tool->name === self::STRUCTURED_OUTPUT_TOOL) {
                throw StructuredOutputUnsupportedException::conflictsWith(
                    'anthropic',
                    sprintf('a caller tool named "%s"', self::STRUCTURED_OUTPUT_TOOL),
                    'that name is reserved for the tool this provider forces to enforce a schema; rename the tool',
                );
            }
        }

        $payload['tools'][] = [
            'name'         => self::STRUCTURED_OUTPUT_TOOL,
            'description'  => 'Return the answer in the required structure.',
            'input_schema' => $format->schema,
        ];

        $payload['tool_choice'] = ['type' => 'tool', 'name' => self::STRUCTURED_OUTPUT_TOOL];
    }

    public function toResponse(array $providerResponse): InternalResponse
    {
        $content = null;
        $toolCalls = [];
        $structuredOutput = false;

        foreach ($providerResponse['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content = ($content ?? '') . $block['text'];
            }

            if ($block['type'] === 'tool_use') {
                // The forced tool carries the ANSWER, not a request to call something. Handing it back as a
                // ToolCall would send the Agent loop hunting for a tool the caller never registered.
                if ($block['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                    $content = json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR);
                    $structuredOutput = true;

                    continue;
                }

                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    arguments: $block['input'] ?? [],
                );
            }
        }

        $finishReason = $this->mapStopReason($providerResponse['stop_reason'] ?? 'end_turn');

        if ($structuredOutput) {
            // Nothing was called from the caller's point of view, so `tool_use` would be a lie.
            $finishReason = FinishReason::Stop;
        }

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
                    // Cast to object so an empty array serializes as {} not [].
                    // Anthropic's API requires a JSON object for the input field.
                    'input' => (object) $tc->arguments,
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
