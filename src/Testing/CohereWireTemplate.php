<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

/**
 * The wire shape of Cohere's Chat v2.
 *
 * Text lives in `message.content[]` blocks, tools arrive in `message.tool_calls[]` with their arguments as a JSON
 * string inside `function`, and token usage is nested under `usage.billed_units` rather than at the top level.
 */
final class CohereWireTemplate implements WireTemplate
{
    public function text(string $content, string $model): string
    {
        return $this->encode([
            'id' => 'cohere-fake',
            'finish_reason' => 'COMPLETE',
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $content]],
            ],
            'usage' => $this->usage(),
            'model' => $model,
        ]);
    }

    public function toolCall(string $toolName, array $arguments, string $toolCallId, string $model): string
    {
        return $this->encode([
            'id' => 'cohere-fake',
            'finish_reason' => 'TOOL_CALL',
            'message' => [
                'role' => 'assistant',
                'content' => [],
                'tool_calls' => [[
                    'id' => $toolCallId,
                    'type' => 'function',
                    'function' => [
                        'name' => $toolName,
                        // A JSON string again, as in the OpenAI-compatible family and unlike Anthropic.
                        'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                    ],
                ]],
            ],
            'usage' => $this->usage(),
            'model' => $model,
        ]);
    }

    /** @return array<string, array<string, int>> */
    private function usage(): array
    {
        return ['billed_units' => ['input_tokens' => 1, 'output_tokens' => 1]];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
