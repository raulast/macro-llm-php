<?php

declare(strict_types=1);

namespace MacroLLM\Agent\Memory;

use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tool\ToolCall;

/**
 * SQLite-backed conversation memory. Persists across process restarts.
 * Uses PHP's built-in PDO sqlite driver — no extra dependencies.
 */
final class SqliteMemory implements ConversationMemoryInterface
{
    private \PDO $pdo;

    public function __construct(
        private readonly string $dbPath,
        private readonly string $conversationId,
    ) {
        $this->pdo = new \PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    public function append(InternalMessage $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (conversation_id, role, content, tool_calls_json, tool_call_id, name)
             VALUES (:cid, :role, :content, :tool_calls, :tool_call_id, :name)'
        );

        $stmt->execute([
            ':cid'          => $this->conversationId,
            ':role'         => $message->role->value,
            ':content'      => $message->content,
            ':tool_calls'   => count($message->toolCalls) > 0
                                    ? json_encode(array_map(fn($tc) => [
                                        'id'        => $tc->id,
                                        'name'      => $tc->name,
                                        'arguments' => $tc->arguments,
                                    ], $message->toolCalls))
                                    : null,
            ':tool_call_id' => $message->toolCallId,
            ':name'         => $message->name,
        ]);
    }

    /** @return InternalMessage[] */
    public function getHistory(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role, content, tool_calls_json, tool_call_id, name
             FROM messages WHERE conversation_id = :cid ORDER BY id ASC'
        );
        $stmt->execute([':cid' => $this->conversationId]);

        return array_map(function (array $row): InternalMessage {
            $toolCalls = [];
            if ($row['tool_calls_json'] !== null) {
                foreach (json_decode($row['tool_calls_json'], true) as $tc) {
                    $toolCalls[] = new ToolCall($tc['id'], $tc['name'], $tc['arguments']);
                }
            }

            return new InternalMessage(
                role:       Role::from($row['role']),
                content:    $row['content'],
                toolCalls:  $toolCalls,
                toolCallId: $row['tool_call_id'],
                name:       $row['name'],
            );
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function clear(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE conversation_id = :cid');
        $stmt->execute([':cid' => $this->conversationId]);
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id TEXT    NOT NULL,
                role            TEXT    NOT NULL,
                content         TEXT,
                tool_calls_json TEXT,
                tool_call_id    TEXT,
                name            TEXT,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_cid ON messages (conversation_id)');
    }
}
