<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

/**
 * The wire shape of Gemini's `generateContent`.
 *
 * Text lives in `candidates[0].content.parts[].text`, a tool call is a `functionCall` part whose arguments are in
 * `args`, and there is no dedicated tool-call finish reason — the API reports `STOP` and the `functionCall` part is
 * what tells an adapter a call was made.
 */
final class GeminiWireTemplate implements WireTemplate
{
    public function text(string $content, string $model): string
    {
        return $this->encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => $content]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $this->usage(),
            'modelVersion' => $model,
        ]);
    }

    public function toolCall(string $toolName, array $arguments, string $toolCallId, string $model): string
    {
        return $this->encode([
            'candidates' => [[
                'content' => [
                    'parts' => [['functionCall' => ['name' => $toolName, 'args' => $arguments]]],
                    'role' => 'model',
                ],
                // Not a tool-specific reason: Gemini reports STOP and the part carries the call.
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $this->usage(),
            'modelVersion' => $model,
        ]);
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        return ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2];
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
