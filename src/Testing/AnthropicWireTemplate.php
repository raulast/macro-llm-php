<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

/**
 * The wire shape of the Anthropic Messages API.
 *
 * Text lives in `content` BLOCKS rather than a string, tools arrive as a `tool_use` block, and the arguments sit in
 * `input` as a real array — three differences from the OpenAI-compatible family that a consumer would otherwise have
 * to reproduce by hand in every test.
 */
final class AnthropicWireTemplate implements WireTemplate
{
    public function text(string $content, string $model): string
    {
        return $this->encode([
            'id' => 'msg_fake',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => $content]],
            'stop_reason' => 'end_turn',
            'usage' => $this->usage(),
        ]);
    }

    public function toolCall(string $toolName, array $arguments, string $toolCallId, string $model): string
    {
        return $this->encode([
            'id' => 'msg_fake',
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [[
                'type' => 'tool_use',
                'id' => $toolCallId,
                'name' => $toolName,
                'input' => $arguments,
            ]],
            'stop_reason' => 'tool_use',
            'usage' => $this->usage(),
        ]);
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        return ['input_tokens' => 1, 'output_tokens' => 1];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
