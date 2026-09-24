<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

/**
 * The wire shape shared by the ten OpenAI-compatible providers.
 *
 * Derived from the package's own hand-authored fixtures (`tests/Fixtures/provider-responses/openai/`), so the
 * template and the adapter's tests agree by construction rather than by hope. Every field the adapter reads is
 * present; the rest is the minimum a real response carries.
 */
final class OpenAiCompatibleWireTemplate implements WireTemplate
{
    public function text(string $content, string $model): string
    {
        return $this->encode([
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'created' => 0,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]],
            'usage' => $this->usage(),
        ]);
    }

    public function toolCall(string $toolName, array $arguments, string $toolCallId, string $model): string
    {
        return $this->encode([
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'created' => 0,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    // The arguments travel as a JSON STRING here, which is where this format hides them and why a
                    // template is worth having.
                    'tool_calls' => [[
                        'id' => $toolCallId,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolName,
                            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => $this->usage(),
        ]);
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        return ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
