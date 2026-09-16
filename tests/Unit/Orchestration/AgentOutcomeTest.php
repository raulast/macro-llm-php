<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Orchestration;

use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Orchestration\AgentOutcome;
use MacroLLM\Tests\TestCase;
use ReflectionClass;

final class AgentOutcomeTest extends TestCase
{
    private function response(string $content = 'hello'): InternalResponse
    {
        return new InternalResponse($content, FinishReason::Stop);
    }

    // ── Fields ──────────────────────────────────────────────────────────────

    public function test_holds_every_field_unchanged(): void
    {
        $response = $this->response();
        $error    = new \RuntimeException('boom');

        $outcome = new AgentOutcome('agent-a', $response, 12.5, $error);

        $this->assertSame('agent-a', $outcome->agentName);
        $this->assertSame($response, $outcome->response);
        $this->assertSame(12.5, $outcome->durationMs);
        $this->assertSame($error, $outcome->error);
    }

    public function test_error_defaults_to_null_on_success(): void
    {
        $outcome = new AgentOutcome('agent-a', $this->response(), 1.0);

        $this->assertNull($outcome->error);
    }

    public function test_response_can_be_null_on_failure(): void
    {
        $outcome = new AgentOutcome('agent-a', null, 3.25, new \RuntimeException('boom'));

        $this->assertNull($outcome->response);
        $this->assertNotNull($outcome->error);
    }

    public function test_both_response_and_error_can_be_null_simultaneously(): void
    {
        $outcome = new AgentOutcome('agent-a', null, 0.0);

        $this->assertNull($outcome->response);
        $this->assertNull($outcome->error);
    }

    public function test_duration_ms_accepts_a_fractional_and_a_zero_value(): void
    {
        $this->assertSame(0.0, (new AgentOutcome('a', null, 0.0))->durationMs);
        $this->assertSame(0.001, (new AgentOutcome('a', null, 0.001))->durationMs);
    }

    public function test_error_keeps_its_concrete_type_for_caller_inspection(): void
    {
        $outcome = new AgentOutcome('a', null, 1.0, new \InvalidArgumentException('bad arg'));

        $this->assertInstanceOf(\InvalidArgumentException::class, $outcome->error);
        $this->assertSame('bad arg', $outcome->error->getMessage());
    }

    // ── Shape ───────────────────────────────────────────────────────────────

    public function test_class_is_final_readonly_and_every_property_is_readonly(): void
    {
        $reflection = new ReflectionClass(AgentOutcome::class);

        $this->assertTrue($reflection->isFinal());

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('AgentOutcome::$%s must be readonly.', $property->getName()),
            );
        }

        $this->assertCount(4, $reflection->getProperties());
    }
}
