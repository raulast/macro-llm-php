<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Orchestration;

use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Orchestration\AgentOutcome;
use MacroLLM\Orchestration\OrchestratorResult;
use MacroLLM\Tests\TestCase;
use ReflectionClass;

final class OrchestratorResultTest extends TestCase
{
    private function outcome(string $name, string $content = 'reply'): AgentOutcome
    {
        return new AgentOutcome($name, new InternalResponse($content, FinishReason::Stop), 1.5);
    }

    // ── Construction ────────────────────────────────────────────────────────

    public function test_holds_an_empty_outcome_list(): void
    {
        $result = new OrchestratorResult([]);

        $this->assertSame([], $result->outcomes);
    }

    public function test_preserves_outcome_order(): void
    {
        $a = $this->outcome('a');
        $b = $this->outcome('b');
        $c = $this->outcome('c');

        $result = new OrchestratorResult([$a, $b, $c]);

        $this->assertSame([$a, $b, $c], $result->outcomes);
        $this->assertCount(3, $result->outcomes);
    }

    public function test_outcomes_keep_their_identity(): void
    {
        $outcome = $this->outcome('solo');

        $this->assertSame($outcome, (new OrchestratorResult([$outcome]))->outcomes[0]);
    }

    // ── for() lookup ────────────────────────────────────────────────────────

    public function test_for_returns_the_matching_outcome(): void
    {
        $result = new OrchestratorResult([$this->outcome('a'), $this->outcome('b', 'b reply')]);

        $this->assertSame('b reply', $result->for('b')->response->content);
    }

    public function test_for_returns_null_for_an_unknown_agent(): void
    {
        $result = new OrchestratorResult([$this->outcome('a')]);

        $this->assertNull($result->for('nope'));
    }

    public function test_for_returns_null_on_an_empty_result(): void
    {
        $this->assertNull((new OrchestratorResult([]))->for('anything'));
    }

    public function test_for_returns_the_first_match_when_a_name_repeats(): void
    {
        // Documents the linear scan: the first matching entry wins.
        $result = new OrchestratorResult([
            $this->outcome('dup', 'first'),
            $this->outcome('dup', 'second'),
        ]);

        $this->assertSame('first', $result->for('dup')->response->content);
    }

    public function test_for_looks_up_failed_outcomes_too(): void
    {
        $failed = new AgentOutcome('boom', null, 2.0, new \RuntimeException('nope'));
        $result = new OrchestratorResult([$failed]);

        $this->assertSame($failed, $result->for('boom'));
        $this->assertNotNull($result->for('boom')->error);
    }

    // ── Shape ───────────────────────────────────────────────────────────────

    public function test_class_is_final_and_outcomes_is_readonly(): void
    {
        $reflection = new ReflectionClass(OrchestratorResult::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getProperty('outcomes')->isReadOnly());
    }
}
