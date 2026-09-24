<?php

declare(strict_types=1);

namespace MacroLLM\Approval;

/**
 * A tool call waiting for a human answer.
 *
 * It carries the arguments as well as the tool name because a decision made without seeing what the tool is about to
 * do is not really a decision. An approver that only knows "the model wants to call delete_record" has nothing to
 * approve; one that can see which record it will delete has something to refuse.
 */
final class PendingApproval
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly array $arguments = [],
        public readonly string $description = '',
    ) {}

    /** A one-line summary fit for a prompt or a log. */
    public function summary(): string
    {
        $arguments = json_encode($this->arguments, JSON_UNESCAPED_SLASHES) ?: '{}';

        return sprintf('%s(%s)', $this->toolName, $arguments);
    }

    /** @return array{toolCallId: string, toolName: string, arguments: array<string, mixed>, description: string} */
    public function toArray(): array
    {
        return [
            'toolCallId' => $this->toolCallId,
            'toolName' => $this->toolName,
            'arguments' => $this->arguments,
            'description' => $this->description,
        ];
    }
}
