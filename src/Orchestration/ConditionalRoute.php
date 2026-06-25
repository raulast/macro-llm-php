<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

/**
 * Pairs an agent name with the condition that must be true for it to run.
 * The condition receives the previous AgentOutcome (null for the first agent).
 */
final class ConditionalRoute
{
    /**
     * @param string   $agentName
     * @param \Closure(AgentOutcome|null): bool $condition
     */
    public function __construct(
        public readonly string $agentName,
        public readonly \Closure $condition,
    ) {}
}
