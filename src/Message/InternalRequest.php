<?php

declare(strict_types=1);

namespace MacroLLM\Message;

use MacroLLM\Config\Config;
use MacroLLM\Tool\ToolDefinition;

final class InternalRequest
{
    /**
     * @param InternalMessage[]  $messages
     * @param ToolDefinition[]   $tools
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly ?Config $configOverride = null,
        public readonly bool $stream = false,
    ) {}

    /** @param InternalMessage[] $messages */
    public function withMessages(array $messages): self
    {
        return new self(
            messages: $messages,
            tools: $this->tools,
            configOverride: $this->configOverride,
            stream: $this->stream,
        );
    }

    public function appended(InternalMessage ...$messages): self
    {
        return new self(
            messages: array_merge($this->messages, $messages),
            tools: $this->tools,
            configOverride: $this->configOverride,
            stream: $this->stream,
        );
    }
}
