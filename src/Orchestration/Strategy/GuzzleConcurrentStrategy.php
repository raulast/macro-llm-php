<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration\Strategy;

use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Utils;
use MacroLLM\Contract\ConcurrencyStrategyInterface;
use MacroLLM\Message\InternalResponse;

/**
 * Primary parallel strategy.
 *
 * Uses Guzzle Promises (Utils::settle) for a consistent concurrency interface.
 * Since Agent::run() is synchronous, this provides sequential execution with
 * a uniform API. True HTTP concurrency requires async provider implementations.
 */
final class GuzzleConcurrentStrategy implements ConcurrencyStrategyInterface
{
    /**
     * @param array<string, callable(): InternalResponse> $tasks
     * @return array<string, InternalResponse|\Throwable>
     */
    public function run(array $tasks): array
    {
        $promises = [];

        foreach ($tasks as $name => $task) {
            $promises[$name] = Coroutine::of(function () use ($task) {
                yield $task();
            });
        }

        $results = Utils::settle($promises)->wait();

        $output = [];
        foreach ($results as $name => $result) {
            $output[$name] = $result['state'] === 'fulfilled'
                ? $result['value']
                : $result['reason'];
        }

        return $output;
    }
}
