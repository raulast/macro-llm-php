<?php

declare(strict_types=1);

namespace MacroLLM\Message;

use MacroLLM\Tool\ToolCall;

final class InternalResponse
{
    /**
     * @param ToolCall[]            $toolCalls
     * @param array<string, mixed>  $extra  Preserves non-mapped provider fields
     * @param string|null           $providerName Which provider produced this response. With a failover chain
     *                                           configured it is NOT necessarily the one that was requested, and a
     *                                           caller who needs to know where an answer came from can ask.
     */
    public function __construct(
        public readonly ?string $content,
        public readonly FinishReason $finishReason,
        public readonly array $toolCalls = [],
        public readonly Usage $usage = new Usage(),
        public readonly array $extra = [],
        public readonly ?string $providerName = null,
    ) {}

    /** The same response, attributed to the provider that produced it. */
    public function withProviderName(string $providerName): self
    {
        return new self(
            content: $this->content,
            finishReason: $this->finishReason,
            toolCalls: $this->toolCalls,
            usage: $this->usage,
            extra: $this->extra,
            providerName: $providerName,
        );
    }

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
