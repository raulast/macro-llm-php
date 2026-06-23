<?php

declare(strict_types=1);

namespace MacroLLM\Agent;

/**
 * Identifies the type of event fired by the Agent tool-call loop.
 *
 * Used in {@see AgentStep} to discriminate which context fields are populated.
 */
enum AgentStepType: string
{
    /** The LLM responded with one or more tool calls — the loop will continue. */
    case LlmResponse = 'llm_response';

    /** The agent is about to execute a single tool call. */
    case ToolCall = 'tool_call';

    /** A single tool call has finished executing (success or error). */
    case ToolResult = 'tool_result';

    /** The LLM responded with no tool calls — this is the final response. */
    case FinalResponse = 'final_response';
}
