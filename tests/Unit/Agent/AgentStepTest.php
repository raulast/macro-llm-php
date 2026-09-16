<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent;

use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolResult;
use ReflectionClass;

/**
 * Pins the {@see AgentStep} contract: which context fields are populated for each
 * {@see AgentStepType}. The mapping below is documented on the class itself, so a
 * change to it is a contract change and must fail here first.
 */
final class AgentStepTest extends TestCase
{
    private function response(): InternalResponse
    {
        return new InternalResponse(
            content: 'assistant text',
            finishReason: FinishReason::Stop,
        );
    }

    private function toolCall(): ToolCall
    {
        return new ToolCall(id: 'tc_1', name: 'get_weather', arguments: ['city' => 'Madrid']);
    }

    private function toolResult(): ToolResult
    {
        return ToolResult::ok('tc_1', 'get_weather', 'Sunny');
    }

    // ── Enum values ─────────────────────────────────────────────────────────

    public function test_step_type_enum_values_are_stable_wire_strings(): void
    {
        $this->assertSame('llm_response', AgentStepType::LlmResponse->value);
        $this->assertSame('tool_call', AgentStepType::ToolCall->value);
        $this->assertSame('tool_result', AgentStepType::ToolResult->value);
        $this->assertSame('final_response', AgentStepType::FinalResponse->value);
    }

    public function test_step_type_enum_has_exactly_four_cases(): void
    {
        $this->assertCount(4, AgentStepType::cases());
    }

    // ── Defaults ────────────────────────────────────────────────────────────

    public function test_only_type_and_iteration_are_required(): void
    {
        $step = new AgentStep(type: AgentStepType::FinalResponse, iteration: 1);

        $this->assertNull($step->response);
        $this->assertNull($step->toolCall);
        $this->assertNull($step->toolResult);
    }

    public function test_iteration_is_one_based_and_accepts_arbitrary_values(): void
    {
        $this->assertSame(1, (new AgentStep(AgentStepType::LlmResponse, 1))->iteration);
        $this->assertSame(7, (new AgentStep(AgentStepType::LlmResponse, 7))->iteration);
    }

    // ── Field pass-through ──────────────────────────────────────────────────

    public function test_all_fields_survive_construction_unchanged(): void
    {
        $response = $this->response();
        $toolCall = $this->toolCall();
        $toolResult = $this->toolResult();

        $step = new AgentStep(
            type: AgentStepType::ToolResult,
            iteration: 3,
            response: $response,
            toolCall: $toolCall,
            toolResult: $toolResult,
        );

        $this->assertSame(AgentStepType::ToolResult, $step->type);
        $this->assertSame(3, $step->iteration);
        $this->assertSame($response, $step->response);
        $this->assertSame($toolCall, $step->toolCall);
        $this->assertSame($toolResult, $step->toolResult);
    }

    // ── Documented type → populated-fields matrix ───────────────────────────

    public function test_llm_response_step_carries_only_the_response(): void
    {
        $step = new AgentStep(
            type: AgentStepType::LlmResponse,
            iteration: 1,
            response: $this->response(),
        );

        $this->assertNotNull($step->response);
        $this->assertNull($step->toolCall);
        $this->assertNull($step->toolResult);
    }

    public function test_tool_call_step_carries_the_call_but_no_result(): void
    {
        $step = new AgentStep(
            type: AgentStepType::ToolCall,
            iteration: 1,
            toolCall: $this->toolCall(),
        );

        // toolResult is still null here — this step fires BEFORE execution.
        $this->assertNull($step->response);
        $this->assertNotNull($step->toolCall);
        $this->assertNull($step->toolResult);
    }

    public function test_tool_result_step_carries_both_call_and_result(): void
    {
        $step = new AgentStep(
            type: AgentStepType::ToolResult,
            iteration: 1,
            toolCall: $this->toolCall(),
            toolResult: $this->toolResult(),
        );

        $this->assertNull($step->response);
        $this->assertNotNull($step->toolCall);
        $this->assertNotNull($step->toolResult);
    }

    public function test_final_response_step_carries_only_the_response(): void
    {
        $step = new AgentStep(
            type: AgentStepType::FinalResponse,
            iteration: 2,
            response: $this->response(),
        );

        $this->assertNotNull($step->response);
        $this->assertNull($step->toolCall);
        $this->assertNull($step->toolResult);
    }

    public function test_tool_result_step_is_the_only_one_carrying_a_tool_result(): void
    {
        $payloads = [
            AgentStepType::LlmResponse->value   => $this->response(),
            AgentStepType::ToolCall->value      => $this->toolCall(),
            AgentStepType::FinalResponse->value => $this->response(),
        ];

        foreach ($payloads as $expectedType => $payload) {
            $step = $payload instanceof ToolCall
                ? new AgentStep(AgentStepType::ToolCall, 1, toolCall: $payload)
                : new AgentStep(AgentStepType::from($expectedType), 1, response: $payload);

            $this->assertNull(
                $step->toolResult,
                sprintf('%s must not populate toolResult.', $step->type->value),
            );
        }

        $withResult = new AgentStep(
            type: AgentStepType::ToolResult,
            iteration: 1,
            toolCall: $this->toolCall(),
            toolResult: $this->toolResult(),
        );

        $this->assertNotNull($withResult->toolResult);
    }

    // ── Immutability ────────────────────────────────────────────────────────

    public function test_every_property_is_readonly(): void
    {
        $reflection = new ReflectionClass(AgentStep::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('AgentStep::$%s must be readonly.', $property->getName()),
            );
        }

        $this->assertCount(5, $reflection->getProperties());
    }

    public function test_class_is_final(): void
    {
        $this->assertTrue((new ReflectionClass(AgentStep::class))->isFinal());
    }
}
