<?php

declare(strict_types=1);

namespace MacroLLM\Message;

use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolResult;

final class InternalMessage
{
    /** @param ToolCall[] $toolCalls */
    public function __construct(
        public readonly Role $role,
        public readonly ?string $content,
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(role: Role::System, content: $content);
    }

    public static function user(string $content): self
    {
        return new self(role: Role::User, content: $content);
    }

    /** @param ToolCall[] $toolCalls */
    public static function assistant(?string $content, array $toolCalls = []): self
    {
        return new self(role: Role::Assistant, content: $content, toolCalls: $toolCalls);
    }

    public static function tool(ToolResult $result): self
    {
        return new self(
            role: Role::Tool,
            content: is_string($result->content) ? $result->content : json_encode($result->content),
            toolCallId: $result->toolCallId,
            name: $result->name,
        );
    }
}
