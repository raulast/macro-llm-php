<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

use MacroLLM\Message\InternalResponse;

final readonly class AgentOutcome
{
    public function __construct(
        public string $agentName,
        public ?InternalResponse $response,
        public float $durationMs,
        public ?\Throwable $error = null,
    ) {}
}
