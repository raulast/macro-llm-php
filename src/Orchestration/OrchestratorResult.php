<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

final class OrchestratorResult
{
    /** @param AgentOutcome[] $outcomes */
    public function __construct(public readonly array $outcomes)
    {
    }

    public function for(string $agentName): ?AgentOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->agentName === $agentName) {
                return $outcome;
            }
        }

        return null;
    }
}
