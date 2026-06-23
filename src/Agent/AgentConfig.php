<?php

declare(strict_types=1);

namespace MacroLLM\Agent;

use Closure;
use MacroLLM\Agent\Memory\NullMemory;
use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Tool\ToolDefinition;

final class AgentConfig
{
    /**
     * @param string|null $provider Provider name override (null → global default)
     * @param string|null $systemPrompt Direct system prompt
     * @param ToolDefinition[] $tools Direct tool definitions
     * @param string[] $skillNames Skill names to resolve from SkillRegistry
     * @param string $skillSeparator Separator for composing skill prompts
     * @param int $maxIterations Maximum tool-call loop iterations
     * @param ConversationMemoryInterface $memory Conversation memory implementation
     * @param Closure(AgentStep): void|null $onStep Optional step callback fired at each
     *        meaningful event in the tool-call loop (LlmResponse, ToolCall, ToolResult,
     *        FinalResponse). Exceptions thrown by the callback propagate to the caller.
     *        Pass null (default) to disable — zero overhead on the hot path.
     */
    public function __construct(
        public readonly ?string $provider = null,
        public readonly ?string $systemPrompt = null,
        public readonly array $tools = [],
        public readonly array $skillNames = [],
        public readonly string $skillSeparator = "\n\n",
        public readonly int $maxIterations = 10,
        public readonly ConversationMemoryInterface $memory = new NullMemory(),
        public readonly ?Closure $onStep = null,
    ) {}
}
