<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Orchestration;

use ArrayObject;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Contract\ConcurrencyStrategyInterface;
use MacroLLM\MacroLLM;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Orchestration\AgentOutcome;
use MacroLLM\Orchestration\ErrorStrategy;
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\OrchestratorResult;
use MacroLLM\Orchestration\RoutingStrategy;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;

/**
 * Orchestrator routing and error-strategy behavior.
 *
 * Parallel routing is driven through an injected ConcurrencyStrategyInterface fake
 * rather than Guzzle's concurrent pool, so ordering and error propagation are
 * deterministic. Sequential routing uses the @internal httpHandlerFactory seam with
 * a history middleware, which lets us assert what the NEXT agent actually received.
 */
final class OrchestratorTest extends TestCase
{
    // ── Infrastructure ────────────────────────────────────────────────────────

    private function openAIResponseJson(string $content): string
    {
        return json_encode([
            'id'      => 'chatcmpl-test',
            'object'  => 'chat.completion',
            'model'   => 'gpt-4o',
            'choices' => [[
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
            'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ]);
    }

    private function makeLLM(array $queue, ArrayObject $history): MacroLLM
    {
        $tools  = new ToolRegistry();
        $skills = new SkillRegistry($tools);

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        $providers = new ProviderRegistry();
        $providers->register(new OpenAIProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://fake-openai.test',
        )));

        return new MacroLLM(
            new Config(defaultProvider: 'openai'),
            $providers,
            $tools,
            $skills,
            httpHandlerFactory: fn() => $stack,
        );
    }

    /** Text responses queued in order, plus a recorded request history. */
    private function llmWithResponses(string ...$contents): array
    {
        $history = new ArrayObject();
        $queue   = array_map(
            fn(string $c) => new Response(200, ['Content-Type' => 'application/json'], $this->openAIResponseJson($c)),
            $contents,
        );

        return [$this->makeLLM($queue, $history), $history];
    }

    /** Runs the queued callables in order and returns their results verbatim. */
    private function fakeConcurrency(): ConcurrencyStrategyInterface
    {
        return new class implements ConcurrencyStrategyInterface {
            public function run(array $tasks): array
            {
                $results = [];

                foreach ($tasks as $name => $task) {
                    try {
                        $results[$name] = $task();
                    } catch (\Throwable $e) {
                        $results[$name] = $e;
                    }
                }

                return $results;
            }
        };
    }

    private function agent(MacroLLM $llm): \MacroLLM\Agent\Agent
    {
        return $llm->agent(new AgentConfig(provider: 'openai'));
    }

    // ── Sequential ────────────────────────────────────────────────────────────

    public function test_default_routing_is_sequential(): void
    {
        [$llm] = $this->llmWithResponses('one');

        $orchestrator = new Orchestrator();
        $orchestrator->addAgent('solo', $this->agent($llm));

        $result = $orchestrator->dispatch('go');

        $this->assertSame('one', $result->for('solo')->response->content);
    }

    public function test_sequential_dispatches_every_agent_in_registration_order(): void
    {
        [$llm] = $this->llmWithResponses('first reply', 'second reply');

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential);
        $orchestrator->addAgent('a', $this->agent($llm));
        $orchestrator->addAgent('b', $this->agent($llm));

        $result = $orchestrator->dispatch('go');

        $this->assertCount(2, $result->outcomes);
        $this->assertSame('a', $result->outcomes[0]->agentName);
        $this->assertSame('b', $result->outcomes[1]->agentName);
    }

    public function test_sequential_chains_the_previous_response_into_the_next_agent(): void
    {
        [$llm, $history] = $this->llmWithResponses('first reply', 'second reply');

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential);
        $orchestrator->addAgent('a', $this->agent($llm));
        $orchestrator->addAgent('b', $this->agent($llm));

        $orchestrator->dispatch('original task');

        // The FIRST request carries only the user turn.
        $first = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame([['role' => 'user', 'content' => 'original task']], $first['messages']);

        // The SECOND request carries the original task plus the previous assistant turn.
        $second = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame('user', $second['messages'][0]['role']);
        $this->assertSame('original task', $second['messages'][0]['content']);
        $this->assertSame('assistant', $second['messages'][1]['role']);
        $this->assertSame('first reply', $second['messages'][1]['content']);
    }

    public function test_sequential_records_a_non_negative_duration_per_agent(): void
    {
        [$llm] = $this->llmWithResponses('one', 'two');

        $orchestrator = new Orchestrator();
        $orchestrator->addAgent('a', $this->agent($llm));
        $orchestrator->addAgent('b', $this->agent($llm));

        foreach ($orchestrator->dispatch('go')->outcomes as $outcome) {
            $this->assertGreaterThan(0.0, $outcome->durationMs);
            $this->assertNull($outcome->error);
        }
    }

    public function test_sequential_with_no_agents_returns_an_empty_result(): void
    {
        $result = (new Orchestrator())->dispatch('nobody home');

        $this->assertInstanceOf(OrchestratorResult::class, $result);
        $this->assertSame([], $result->outcomes);
    }

    public function test_sequential_stop_strategy_propagates_the_first_failure(): void
    {
        $history = new ArrayObject();
        // A 400 is non-retryable, so the first agent throws immediately.
        $llm = $this->makeLLM([new Response(400, [], '{"error":"bad request"}')], $history);

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential, errorStrategy: ErrorStrategy::Stop);
        $orchestrator->addAgent('a', $this->agent($llm));

        $this->expectException(\MacroLLM\Exception\ProviderRequestException::class);

        $orchestrator->dispatch('go');
    }

    public function test_sequential_continue_strategy_records_the_error_and_keeps_going(): void
    {
        $history = new ArrayObject();
        $llm = $this->makeLLM([
            new Response(400, [], '{"error":"bad request"}'),
            new Response(200, ['Content-Type' => 'application/json'], $this->openAIResponseJson('recovered')),
        ], $history);

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential, errorStrategy: ErrorStrategy::Continue);
        $orchestrator->addAgent('a', $this->agent($llm));
        $orchestrator->addAgent('b', $this->agent($llm));

        $result = $orchestrator->dispatch('go');

        $this->assertCount(2, $result->outcomes);
        $this->assertNotNull($result->outcomes[0]->error);
        $this->assertNull($result->outcomes[0]->response);
        $this->assertSame('recovered', $result->outcomes[1]->response->content);
    }

    public function test_sequential_continue_does_not_chain_a_failed_turn(): void
    {
        $history = new ArrayObject();
        $llm = $this->makeLLM([
            new Response(400, [], '{"error":"bad request"}'),
            new Response(200, ['Content-Type' => 'application/json'], $this->openAIResponseJson('second')),
        ], $history);

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential, errorStrategy: ErrorStrategy::Continue);
        $orchestrator->addAgent('a', $this->agent($llm));
        $orchestrator->addAgent('b', $this->agent($llm));

        $orchestrator->dispatch('original task');

        // previousResponse is only advanced on success, so agent 'b' still gets the bare task.
        $second = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertCount(1, $second['messages']);
        $this->assertSame('user', $second['messages'][0]['role']);
    }

    // ── Parallel ──────────────────────────────────────────────────────────────

    public function test_parallel_collects_every_agent_outcome(): void
    {
        [$llm] = $this->llmWithResponses('alpha reply', 'beta reply');

        $orchestrator = new Orchestrator(
            routing: RoutingStrategy::Parallel,
            concurrency: $this->fakeConcurrency(),
        );
        $orchestrator->addAgent('alpha', $this->agent($llm));
        $orchestrator->addAgent('beta', $this->agent($llm));

        $result = $orchestrator->dispatch('go');

        $this->assertCount(2, $result->outcomes);
        $this->assertSame('alpha reply', $result->for('alpha')->response->content);
        $this->assertSame('beta reply', $result->for('beta')->response->content);
    }

    public function test_parallel_gives_every_agent_the_same_original_task(): void
    {
        [$llm, $history] = $this->llmWithResponses('alpha reply', 'beta reply');

        $orchestrator = new Orchestrator(
            routing: RoutingStrategy::Parallel,
            concurrency: $this->fakeConcurrency(),
        );
        $orchestrator->addAgent('alpha', $this->agent($llm));
        $orchestrator->addAgent('beta', $this->agent($llm));

        $orchestrator->dispatch('shared task');

        foreach ([0, 1] as $i) {
            $body = json_decode((string) $history[$i]['request']->getBody(), true);
            $this->assertCount(1, $body['messages'], 'Parallel agents must not chain each other.');
            $this->assertSame('shared task', $body['messages'][0]['content']);
        }
    }

    public function test_parallel_continue_strategy_keeps_successful_outcomes_and_records_the_failure(): void
    {
        $failing = new class implements ConcurrencyStrategyInterface {
            public function run(array $tasks): array
            {
                return [
                    'alpha' => new InternalResponse('alpha ok', FinishReason::Stop),
                    'beta'  => new \RuntimeException('beta exploded'),
                ];
            }
        };

        $orchestrator = new Orchestrator(
            routing: RoutingStrategy::Parallel,
            errorStrategy: ErrorStrategy::Continue,
            concurrency: $failing,
        );
        $orchestrator->addAgent('alpha', $this->agent($this->makeLLM([], new ArrayObject())));
        $orchestrator->addAgent('beta', $this->agent($this->makeLLM([], new ArrayObject())));

        $result = $orchestrator->dispatch('go');

        $this->assertCount(2, $result->outcomes);
        $this->assertSame('alpha ok', $result->for('alpha')->response->content);
        $this->assertNull($result->for('alpha')->error);
        $this->assertNull($result->for('beta')->response);
        $this->assertInstanceOf(\RuntimeException::class, $result->for('beta')->error);
    }

    public function test_parallel_stop_strategy_rethrows_a_failed_agent(): void
    {
        $failing = new class implements ConcurrencyStrategyInterface {
            public function run(array $tasks): array
            {
                return ['alpha' => new \RuntimeException('alpha exploded')];
            }
        };

        $orchestrator = new Orchestrator(
            routing: RoutingStrategy::Parallel,
            errorStrategy: ErrorStrategy::Stop,
            concurrency: $failing,
        );
        $orchestrator->addAgent('alpha', $this->agent($this->makeLLM([], new ArrayObject())));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('alpha exploded');

        $orchestrator->dispatch('go');
    }

    public function test_parallel_with_no_agents_returns_an_empty_result(): void
    {
        $orchestrator = new Orchestrator(
            routing: RoutingStrategy::Parallel,
            concurrency: $this->fakeConcurrency(),
        );

        $this->assertSame([], $orchestrator->dispatch('go')->outcomes);
    }

    // ── Conditional ───────────────────────────────────────────────────────────

    public function test_adding_a_conditional_agent_switches_routing_to_conditional(): void
    {
        // The routing switch is observed through dispatch(), not through a getter.
        [$llm] = $this->llmWithResponses('reply');

        $orchestrator = new Orchestrator(routing: RoutingStrategy::Sequential);
        $orchestrator->addConditionalAgent('a', $this->agent($llm), fn() => true);

        // A second conditional agent whose condition rejects everything must be skipped,
        // which can only happen if routing became Conditional.
        $orchestrator->addConditionalAgent('b', $this->agent($llm), fn() => false);

        $this->assertCount(1, $orchestrator->dispatch('go')->outcomes);
    }

    public function test_conditional_condition_receives_null_for_the_first_agent(): void
    {
        [$llm] = $this->llmWithResponses('reply');

        $received = [];
        $orchestrator = new Orchestrator();
        $orchestrator->addConditionalAgent(
            'first',
            $this->agent($llm),
            function (?AgentOutcome $previous) use (&$received): bool {
                $received[] = $previous;

                return true;
            },
        );

        $orchestrator->dispatch('go');

        $this->assertSame([null], $received);
    }

    public function test_conditional_condition_receives_the_previous_outcome(): void
    {
        [$llm] = $this->llmWithResponses('first reply', 'second reply');

        $received = [];
        $orchestrator = new Orchestrator();
        $orchestrator->addConditionalAgent('a', $this->agent($llm), fn() => true);
        $orchestrator->addConditionalAgent(
            'b',
            $this->agent($llm),
            function (?AgentOutcome $previous) use (&$received): bool {
                $received[] = $previous;

                return true;
            },
        );

        $orchestrator->dispatch('go');

        $this->assertCount(1, $received);
        $this->assertInstanceOf(AgentOutcome::class, $received[0]);
        $this->assertSame('a', $received[0]->agentName);
        $this->assertSame('first reply', $received[0]->response->content);
    }

    public function test_conditional_skipped_agents_never_run_and_leave_no_outcome(): void
    {
        [$llm, $history] = $this->llmWithResponses('only reply');

        $orchestrator = new Orchestrator();
        $orchestrator->addConditionalAgent('runs', $this->agent($llm), fn() => true);
        $orchestrator->addConditionalAgent('skipped', $this->agent($llm), fn() => false);

        $result = $orchestrator->dispatch('go');

        $this->assertCount(1, $result->outcomes);
        $this->assertSame('runs', $result->outcomes[0]->agentName);
        $this->assertNull($result->for('skipped'));
        $this->assertCount(1, $history, 'A skipped agent must not make an HTTP call.');
    }

    public function test_conditional_false_first_condition_skips_leading_agent(): void
    {
        [$llm] = $this->llmWithResponses('second reply');

        $orchestrator = new Orchestrator();
        $orchestrator->addConditionalAgent('gate', $this->agent($llm), fn() => false);
        $orchestrator->addConditionalAgent('body', $this->agent($llm), fn() => true);

        $result = $orchestrator->dispatch('go');

        $this->assertCount(1, $result->outcomes);
        $this->assertSame('body', $result->outcomes[0]->agentName);
    }

    public function test_conditional_engages_error_strategy_stop(): void
    {
        $history = new ArrayObject();
        $llm = $this->makeLLM([new Response(400, [], '{"error":"bad"}')], $history);

        $orchestrator = new Orchestrator(errorStrategy: ErrorStrategy::Stop);
        $orchestrator->addConditionalAgent('a', $this->agent($llm), fn() => true);

        $this->expectException(\MacroLLM\Exception\ProviderRequestException::class);

        $orchestrator->dispatch('go');
    }

    public function test_conditional_engages_error_strategy_continue_and_chains_the_failed_outcome(): void
    {
        $history = new ArrayObject();
        $llm = $this->makeLLM([
            new Response(400, [], '{"error":"bad"}'),
            new Response(200, ['Content-Type' => 'application/json'], $this->openAIResponseJson('recovered')),
        ], $history);

        $received = [];
        $orchestrator = new Orchestrator(errorStrategy: ErrorStrategy::Continue);
        $orchestrator->addConditionalAgent('a', $this->agent($llm), fn() => true);
        $orchestrator->addConditionalAgent(
            'b',
            $this->agent($llm),
            function (?AgentOutcome $previous) use (&$received): bool {
                $received[] = $previous;

                return true;
            },
        );

        $result = $orchestrator->dispatch('go');

        $this->assertCount(2, $result->outcomes);
        // Unlike sequential, conditional DOES advance previousOutcome past a failure.
        $this->assertInstanceOf(AgentOutcome::class, $received[0]);
        $this->assertSame('a', $received[0]->agentName);
        $this->assertNotNull($received[0]->error);
    }

    public function test_agent_registered_without_a_condition_always_runs_in_conditional_mode(): void
    {
        [$llm] = $this->llmWithResponses('always reply', 'gated reply');

        $orchestrator = new Orchestrator();
        $orchestrator->addAgent('always', $this->agent($llm));
        $orchestrator->addConditionalAgent('gated', $this->agent($llm), fn() => false);

        $result = $orchestrator->dispatch('go');

        // Mixed mode: an agent with no ConditionalRoute is never filtered out.
        $this->assertCount(1, $result->outcomes);
        $this->assertSame('always', $result->outcomes[0]->agentName);
    }

    public function test_re_adding_an_agent_name_replaces_the_previous_agent(): void
    {
        [$llm] = $this->llmWithResponses('the replacement reply');

        $orchestrator = new Orchestrator();
        $orchestrator->addAgent('dup', $this->agent($llm));
        $orchestrator->addAgent('dup', $this->agent($llm));

        $result = $orchestrator->dispatch('go');

        $this->assertCount(1, $result->outcomes);
    }

    public function test_conditional_with_no_agents_returns_an_empty_result(): void
    {
        $orchestrator = new Orchestrator(routing: RoutingStrategy::Conditional);

        $this->assertSame([], $orchestrator->dispatch('go')->outcomes);
    }
}
