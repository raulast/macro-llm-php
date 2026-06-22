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

final class GeminiProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    public function endpointPath(): string
    {
        return '/models/' . $this->config->defaultModel . ':generateContent';
    }

    /**
     * Gemini uses x-goog-api-key header for server-to-server auth.
     */
    public function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $this->requireApiKey(),
        ];
    }

    public function toPayload(InternalRequest $request): array
    {
        $systemInstruction = null;
        $contents = [];

        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                $systemInstruction = ($systemInstruction ?? '') .
                    ($systemInstruction !== null ? "\n\n" : '') . $message->content;
                continue;
            }

            $contents[] = $this->mapMessage($message);
        }

        $payload = [
            'contents' => $contents,
        ];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        if (count($request->tools) > 0) {
            $payload['tools'] = [
                ['functionDeclarations' => $this->mapTools($request->tools)],
            ];
        }

        return $payload;
    }

    public function toResponse(array $providerResponse): InternalResponse
    {
        $candidate = $providerResponse['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $content = null;
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content = ($content ?? '') . $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = new ToolCall(
                    id: $part['functionCall']['name'] . '_' . uniqid(),
                    name: $part['functionCall']['name'],
                    arguments: $part['functionCall']['args'] ?? [],
                );
            }
        }

        $finishReason = $this->mapFinishReason($candidate['finishReason'] ?? 'STOP');

        $usage = new Usage();
        if (isset($providerResponse['usageMetadata'])) {
            $usage = new Usage(
                promptTokens: $providerResponse['usageMetadata']['promptTokenCount'] ?? 0,
                completionTokens: $providerResponse['usageMetadata']['candidatesTokenCount'] ?? 0,
                totalTokens: $providerResponse['usageMetadata']['totalTokenCount'] ?? 0,
            );
        }

        $knownKeys = ['candidates', 'usageMetadata', 'modelVersion'];
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

        if ($rawEvent === '' || $rawEvent === '[' || $rawEvent === ']' || $rawEvent === ',') {
            return null;
        }

        // Gemini streaming uses newline-delimited JSON objects (or JSON array elements)
        $cleanedJson = ltrim($rawEvent, ',');
        $data = json_decode($cleanedJson, true);

        if ($data === null) {
            return null;
        }

        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        $text = '';

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $finishReason = $candidate['finishReason'] ?? null;
        $finished = $finishReason !== null && $finishReason !== 'STOP';

        // STOP with content is normal completion
        if ($finishReason === 'STOP') {
            $finished = true;
        }

        return new StreamChunk(
            delta: $text,
            index: $index,
            finished: $finished,
        );
    }

    public function getModels(): array
    {
        return [
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite',
            'gemini-2.0-flash-thinking-exp-01-21',
            'gemini-1.5-pro',
            'gemini-1.5-pro-002',
            'gemini-1.5-flash',
            'gemini-1.5-flash-002',
            'gemini-1.5-flash-8b',
            'gemini-1.0-pro',
        ];
    }

    private function mapMessage(InternalMessage $message): array
    {
        $role = match ($message->role) {
            Role::User => 'user',
            Role::Assistant => 'model',
            Role::Tool => 'user',
            default => 'user',
        };

        // Function response (tool result)
        if ($message->role === Role::Tool) {
            return [
                'role' => 'user',
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => $message->name ?? '',
                            'response' => ['result' => $message->content ?? ''],
                        ],
                    ],
                ],
            ];
        }

        // Assistant with tool calls (function call parts)
        if ($message->role === Role::Assistant && count($message->toolCalls) > 0) {
            $parts = [];

            if ($message->content !== null) {
                $parts[] = ['text' => $message->content];
            }

            foreach ($message->toolCalls as $tc) {
                $parts[] = [
                    'functionCall' => [
                        'name' => $tc->name,
                        'args' => $tc->arguments,
                    ],
                ];
            }

            return ['role' => 'model', 'parts' => $parts];
        }

        return [
            'role' => $role,
            'parts' => [['text' => $message->content ?? '']],
        ];
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
                'parameters' => $tool->parameters,
            ],
            $tools,
        );
    }

    private function mapFinishReason(?string $reason): FinishReason
    {
        return match ($reason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY' => FinishReason::ContentFilter,
            'RECITATION' => FinishReason::ContentFilter,
            default => FinishReason::Stop,
        };
    }
}
