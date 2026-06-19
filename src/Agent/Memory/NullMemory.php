<?php

declare(strict_types=1);

namespace MacroLLM\Agent\Memory;

use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;

/**
 * Stateless memory — no history is retained.
 * Use as default when conversation persistence is not needed.
 */
final class NullMemory implements ConversationMemoryInterface
{
    public function append(InternalMessage $message): void
    {
        // No-op: stateless memory discards all messages.
    }

    /** @return InternalMessage[] */
    public function getHistory(): array
    {
        return [];
    }

    public function clear(): void
    {
        // No-op: nothing to clear.
    }
}
