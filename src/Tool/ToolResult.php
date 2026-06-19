<?php

declare(strict_types=1);

namespace MacroLLM\Tool;

final class ToolResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        public readonly mixed $content,
        public readonly ToolStatus $status = ToolStatus::Ok,
    ) {}

    public static function ok(string $toolCallId, string $name, mixed $content): self
    {
        return new self(
            toolCallId: $toolCallId,
            name: $name,
            content: $content,
            status: ToolStatus::Ok,
        );
    }

    public static function error(string $toolCallId, string $name, string $message): self
    {
        return new self(
            toolCallId: $toolCallId,
            name: $name,
            content: $message,
            status: ToolStatus::Error,
        );
    }
}
