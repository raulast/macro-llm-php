<?php

declare(strict_types=1);

namespace MacroLLM\Agent\Memory;

use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tool\ToolCall;

/**
 * File-backed conversation memory. Serializes full history as JSON.
 * Simple and portable — not suitable for concurrent access.
 */
final class FileMemory implements ConversationMemoryInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {}

    public function append(InternalMessage $message): void
    {
        $history = $this->load();
        $history[] = $this->serialize($message);
        file_put_contents($this->filePath, json_encode($history, JSON_PRETTY_PRINT));
    }

    /** @return InternalMessage[] */
    public function getHistory(): array
    {
        return array_map(
            fn(array $row) => $this->deserialize($row),
            $this->load(),
        );
    }

    public function clear(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }
        return json_decode(file_get_contents($this->filePath), true) ?? [];
    }

    private function serialize(InternalMessage $m): array
    {
        return [
            'role'         => $m->role->value,
            'content'      => $m->content,
            'tool_calls'   => array_map(fn($tc) => [
                'id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments,
            ], $m->toolCalls),
            'tool_call_id' => $m->toolCallId,
            'name'         => $m->name,
        ];
    }

    private function deserialize(array $row): InternalMessage
    {
        $toolCalls = array_map(
            fn($tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments']),
            $row['tool_calls'] ?? [],
        );

        return new InternalMessage(
            role:       Role::from($row['role']),
            content:    $row['content'] ?? null,
            toolCalls:  $toolCalls,
            toolCallId: $row['tool_call_id'] ?? null,
            name:       $row['name'] ?? null,
        );
    }
}
