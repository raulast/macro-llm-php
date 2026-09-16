<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Orchestration;

use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Orchestration\AgentOutcome;
use MacroLLM\Orchestration\ConditionalRoute;
use MacroLLM\Tests\TestCase;
use ReflectionClass;

final class ConditionalRouteTest extends TestCase
{
    // ── Fields ──────────────────────────────────────────────────────────────

    public function test_holds_the_agent_name_and_condition_unchanged(): void
    {
        $condition = fn(?AgentOutcome $previous): bool => true;

        $route = new ConditionalRoute('agent-a', $condition);

        $this->assertSame('agent-a', $route->agentName);
        $this->assertSame($condition, $route->condition);
    }

    public function test_condition_is_invocable_and_returns_its_own_result(): void
    {
        $route = new ConditionalRoute('agent-a', fn(?AgentOutcome $previous): bool => false);

        $this->assertFalse(($route->condition)(null));
    }

    // ── Condition argument contract ─────────────────────────────────────────

    public function test_condition_accepts_null_as_the_first_agent_argument(): void
    {
        $received = 'not called';
        $route = new ConditionalRoute('agent-a', function (?AgentOutcome $previous) use (&$received): bool {
            $received = $previous;

            return true;
        });

        ($route->condition)(null);

        $this->assertNull($received);
    }

    public function test_condition_receives_the_exact_outcome_instance_passed_in(): void
    {
        $outcome = new AgentOutcome('previous', new InternalResponse('ok', FinishReason::Stop), 5.0);

        $received = null;
        $route = new ConditionalRoute('agent-a', function (?AgentOutcome $previous) use (&$received): bool {
            $received = $previous;

            return true;
        });

        ($route->condition)($outcome);

        $this->assertSame($outcome, $received);
    }

    public function test_condition_can_inspect_a_failed_previous_outcome(): void
    {
        $failed = new AgentOutcome('previous', null, 1.0, new \RuntimeException('boom'));

        $route = new ConditionalRoute(
            'agent-a',
            fn(?AgentOutcome $previous): bool => $previous?->error !== null,
        );

        $this->assertTrue(($route->condition)($failed));
    }

    public function test_condition_can_read_the_previous_response_content(): void
    {
        $outcome = new AgentOutcome('previous', new InternalResponse('YES', FinishReason::Stop), 1.0);

        $route = new ConditionalRoute(
            'agent-a',
            fn(?AgentOutcome $previous): bool => $previous?->response?->content === 'YES',
        );

        $this->assertTrue(($route->condition)($outcome));
    }

    // ── Shape ───────────────────────────────────────────────────────────────

    public function test_class_is_final_and_every_property_is_readonly(): void
    {
        $reflection = new ReflectionClass(ConditionalRoute::class);

        $this->assertTrue($reflection->isFinal());

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('ConditionalRoute::$%s must be readonly.', $property->getName()),
            );
        }

        $this->assertCount(2, $reflection->getProperties());
    }
}
