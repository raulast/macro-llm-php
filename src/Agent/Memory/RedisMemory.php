<?php

declare(strict_types=1);

namespace MacroLLM\Agent\Memory;

use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tool\ToolCall;

/**
 * Redis-backed conversation memory.
 * Accepts any Redis client with rpush/lrange/del methods (phpredis, Predis, or compatible).
 * Messages stored as a Redis list keyed by conversation ID.
 */
final class RedisMemory implements ConversationMemoryInterface
{
    /**
     * @param object $redis  Redis client instance (phpredis \Redis or Predis\Client)
     * @param string $conversationId  List key suffix
     * @param int    $ttl   TTL in seconds (0 = no expiry)
     */
    public function __construct(
        private readonly object $redis,
        private readonly string $conversationId,
        private readonly int $ttl = 0,
    ) {}

    public function append(InternalMessage $message): void
    {
        $key = $this->key();
        $this->redis->rpush($key, json_encode($this->serialize($message)));

        if ($this->ttl > 0) {
            $this->redis->expire($key, $this->ttl);
        }
    }

    /** @return InternalMessage[] */
    public function getHistory(): array
    {
        $raw = $this->redis->lrange($this->key(), 0, -1);
        return array_map(
            fn(string $json) => $this->deserialize(json_decode($json, true)),
            $raw ?: [],
        );
    }

    public function clear(): void
    {
        $this->redis->del($this->key());
    }

    private function key(): string
    {
        return 'macro_llm:memory:' . $this->conversationId;
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
