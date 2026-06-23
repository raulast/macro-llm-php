<?php

declare(strict_types=1);

namespace MacroLLM\Agent;

use MacroLLM\Message\InternalResponse;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolResult;

/**
 * Carries the context of a single event fired during the Agent tool-call loop.
 *
 * Which fields are populated depends on {@see AgentStepType}:
 *
 * | type          | response | toolCall | toolResult |
 * |---------------|----------|----------|------------|
 * | LlmResponse   |    ✓     |          |            |
 * | ToolCall      |          |    ✓     |            |
 * | ToolResult    |          |    ✓     |     ✓      |
 * | FinalResponse |    ✓     |          |            |
 */
final readonly class AgentStep
{
    public function __construct(
        /** The kind of event that occurred. */
        public AgentStepType $type,

        /** The loop iteration index (1-based) in which this step occurred. */
        public int $iteration,

        /** The LLM response. Set on LlmResponse and FinalResponse steps. */
        public ?InternalResponse $response = null,

        /** The tool call being processed. Set on ToolCall and ToolResult steps. */
        public ?ToolCall $toolCall = null,

        /** The result of the tool execution. Set on ToolResult steps only. */
        public ?ToolResult $toolResult = null,
    ) {}
}
