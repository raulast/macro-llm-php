<?php

declare(strict_types=1);

namespace MacroLLM\Message;

use MacroLLM\Tool\ToolCall;

final class InternalResponse
{
    /**
     * @param ToolCall[]            $toolCalls
     * @param array<string, mixed>  $extra  Preserves non-mapped provider fields
     */
    public function __construct(
        public readonly ?string $content,
        public readonly FinishReason $finishReason,
        public readonly array $toolCalls = [],
        public readonly Usage $usage = new Usage(),
        public readonly array $extra = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
