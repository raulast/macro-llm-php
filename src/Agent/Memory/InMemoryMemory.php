<?php

declare(strict_types=1);

namespace MacroLLM\Agent\Memory;

use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;

/**
 * Stateful in-process memory buffer.
 * Retains all appended messages for the lifetime of the instance.
 */
final class InMemoryMemory implements ConversationMemoryInterface
{
    /** @var InternalMessage[] */
    private array $history = [];

    public function append(InternalMessage $message): void
    {
        $this->history[] = $message;
    }

    /** @return InternalMessage[] */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function clear(): void
    {
        $this->history = [];
    }
}
