<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration\Strategy;

use Fiber;
use MacroLLM\Contract\ConcurrencyStrategyInterface;
use MacroLLM\Message\InternalResponse;

/**
 * Secondary strategy for event-loop contexts.
 *
 * Uses PHP Fibers for cooperative scheduling. Note that Fibers alone
 * do NOT provide true parallelism without an event loop driver
 * (ReactPHP, Amphp, etc.).
 */
final class FiberStrategy implements ConcurrencyStrategyInterface
{
    public function __construct(private readonly mixed $eventLoop = null)
    {
    }

    /**
     * @param array<string, callable(): InternalResponse> $tasks
     * @return array<string, InternalResponse|\Throwable>
     */
    public function run(array $tasks): array
    {
        /** @var array<string, Fiber> $fibers */
        $fibers = [];
        $results = [];

        foreach ($tasks as $name => $task) {
            $fibers[$name] = new Fiber(function () use ($task) {
                return $task();
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        foreach ($fibers as $name => $fiber) {
            try {
                $results[$name] = $fiber->getReturn();
            } catch (\Throwable $e) {
                $results[$name] = $e;
            }
        }

        return $results;
    }
}
