<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\InternalResponse;

interface ConcurrencyStrategyInterface
{
    /**
     * Execute tasks concurrently; return map of agent name → InternalResponse | Throwable.
     *
     * @param array<string, callable(): InternalResponse> $tasks
     * @return array<string, InternalResponse|\Throwable>
     */
    public function run(array $tasks): array;
}
