<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

use MacroLLM\Agent\Agent;
use MacroLLM\Contract\ConcurrencyStrategyInterface;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Orchestration\Strategy\GuzzleConcurrentStrategy;

/**
 * Manages multiple agents and routes tasks between them
 * using configurable routing and error strategies.
 */
final class Orchestrator
{
    /** @var array<string, Agent> */
    private array $agents = [];

    public function __construct(
        private RoutingStrategy $routing = RoutingStrategy::Sequential,
        private ErrorStrategy $errorStrategy = ErrorStrategy::Stop,
        private ConcurrencyStrategyInterface $concurrency = new GuzzleConcurrentStrategy(),
    ) {}

    public function addAgent(string $name, Agent $agent): void
    {
        $this->agents[$name] = $agent;
    }

    /**
     * Route task to registered agents per the configured strategy.
     */
    public function dispatch(string|InternalRequest $task): OrchestratorResult
    {
        return match ($this->routing) {
            RoutingStrategy::Parallel => $this->dispatchParallel($task),
            default => $this->dispatchSequential($task),
        };
    }

    private function dispatchSequential(string|InternalRequest $task): OrchestratorResult
    {
        $outcomes = [];
        $previousResponse = null;

        foreach ($this->agents as $name => $agent) {
            $input = $previousResponse !== null
                ? $this->buildFollowUpRequest($task, $previousResponse)
                : $task;

            $start = hrtime(true);

            try {
                $response = $agent->run($input);
                $previousResponse = $response;
                $outcomes[] = new AgentOutcome($name, $response, $this->elapsed($start));
            } catch (\Throwable $e) {
                $outcomes[] = new AgentOutcome($name, null, $this->elapsed($start), $e);

                if ($this->errorStrategy === ErrorStrategy::Stop) {
                    throw $e;
                }
            }
        }

        return new OrchestratorResult($outcomes);
    }

    private function dispatchParallel(string|InternalRequest $task): OrchestratorResult
    {
        $starts = [];
        $taskCallables = [];

        foreach ($this->agents as $name => $agent) {
            $starts[$name] = hrtime(true);
            $taskCallables[$name] = fn() => $agent->run($task);
        }

        $results = $this->concurrency->run($taskCallables);
        $outcomes = [];

        foreach ($results as $name => $result) {
            $durationMs = $this->elapsed($starts[$name]);

            if ($result instanceof \Throwable) {
                $outcomes[] = new AgentOutcome($name, null, $durationMs, $result);

                if ($this->errorStrategy === ErrorStrategy::Stop) {
                    throw $result;
                }
            } else {
                $outcomes[] = new AgentOutcome($name, $result, $durationMs);
            }
        }

        return new OrchestratorResult($outcomes);
    }

    private function buildFollowUpRequest(
        string|InternalRequest $original,
        InternalResponse $previous,
    ): InternalRequest {
        $baseMessages = is_string($original)
            ? [InternalMessage::user($original)]
            : $original->messages;

        return new InternalRequest(
            messages: array_merge($baseMessages, [
                InternalMessage::assistant($previous->content),
            ]),
        );
    }

    private function elapsed(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }
}
