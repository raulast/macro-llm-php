<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\InternalMessage;

interface ConversationMemoryInterface
{
    public function append(InternalMessage $message): void;

    /** @return InternalMessage[] */
    public function getHistory(): array;

    public function clear(): void;
}
