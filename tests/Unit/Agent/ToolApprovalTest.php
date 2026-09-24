<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;
use MacroLLM\Approval\Decision;
use MacroLLM\Approval\PendingApproval;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\ToolApprovalRequiredException;
use MacroLLM\MacroLLM;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Tool\ToolStatus;

/**
 * Tools that declare themselves dangerous do not run until a human says so.
 *
 * Before this, a tool that deleted a record was executed the moment the model asked for it, and the only way to
 * notice was the damage.
 */
final class ToolApprovalTest extends TestCase
{
    private function openAiText(string $content): string
    {
        return json_encode([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function openAiToolCall(string $toolName, array $arguments = [], string $id = 'tc_1'): string
    {
        return json_encode([
            'id' => 'chatcmpl-2',
            'object' => 'chat.completion',
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => json_encode($arguments)],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $queue */
    private function makeLLM(array $queue): MacroLLM
    {
        $stack = HandlerStack::create(new MockHandler(array_map(
            static fn (string $body): Response => new Response(200, ['Content-Type' => 'application/json'], $body),
            $queue,
        )));

        $providers = new ProviderRegistry();
        $providers->register(new OpenAIProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://fake.test',
        )));

        $tools = new ToolRegistry();

        return new MacroLLM(
            new Config(defaultProvider: 'openai'),
            $providers,
            $tools,
            new SkillRegistry($tools),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param bool $ran set to true when the callable runs — the whole question this feature answers */
    private function dangerousTool(bool &$ran): ToolDefinition
    {
        return new ToolDefinition(
            name: 'delete_record',
            description: 'Deletes a record permanently.',
            parameters: [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'string']],
                'required' => ['id'],
            ],
            callable: function () use (&$ran): string {
                $ran = true;

                return 'deleted';
            },
            requiresApproval: true,
        );
    }

    public function test_an_approved_call_runs(): void
    {
        $ran = false;
        $llm = $this->makeLLM([
            $this->openAiToolCall('delete_record', ['id' => '42']),
            $this->openAiText('Done.'),
        ]);

        $response = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$this->dangerousTool($ran)],
            approveToolCalls: fn (PendingApproval $pending): Decision => Decision::Approve,
        ))->run('Delete record 42');

        $this->assertTrue($ran);
        $this->assertSame('Done.', $response->content);
    }

    /**
     * A declined call does not run, and the model is told so as a RESULT rather than an exception: the loop exists for
     * it to try something else, and it cannot do that if the turn ended.
     */
    public function test_a_declined_call_does_not_run_and_the_model_is_told(): void
    {
        $ran = false;
        $results = [];
        $llm = $this->makeLLM([
            $this->openAiToolCall('delete_record', ['id' => '42']),
            $this->openAiText('Understood, I will not delete it.'),
        ]);

        $response = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$this->dangerousTool($ran)],
            approveToolCalls: fn (PendingApproval $pending): Decision => Decision::Reject,
            onStep: function (AgentStep $step) use (&$results): void {
                if ($step->type === AgentStepType::ToolResult && $step->toolResult !== null) {
                    $results[] = $step->toolResult;
                }
            },
        ))->run('Delete record 42');

        $this->assertFalse($ran, 'a declined tool must not run');
        $this->assertSame(ToolStatus::Error, $results[0]->status);
        $this->assertStringContainsString('declined', (string) $results[0]->content);
        $this->assertSame('Understood, I will not delete it.', $response->content);
    }

    /** The approver gets what it needs to decide: which tool, and with which arguments. */
    public function test_the_approver_sees_the_arguments(): void
    {
        $ran = false;
        $seen = null;
        $llm = $this->makeLLM([
            $this->openAiToolCall('delete_record', ['id' => '42']),
            $this->openAiText('ok'),
        ]);

        $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$this->dangerousTool($ran)],
            approveToolCalls: function (PendingApproval $pending) use (&$seen): Decision {
                $seen = $pending;

                return Decision::Reject;
            },
        ))->run('Delete record 42');

        $this->assertNotNull($seen);
        $this->assertSame('delete_record', $seen->toolName);
        $this->assertSame(['id' => '42'], $seen->arguments);
        $this->assertStringContainsString('Deletes a record', $seen->description);
        $this->assertSame('delete_record({"id":"42"})', $seen->summary());
    }

    /**
     * With nobody configured to answer, the call is neither run nor silently denied: the human is handed the pending
     * decision. A quiet denial would let the agent keep working while the person who marked the tool as dangerous
     * never learned it was about to fire.
     */
    public function test_without_an_approver_the_pending_decision_is_raised(): void
    {
        $ran = false;
        $llm = $this->makeLLM([$this->openAiToolCall('delete_record', ['id' => '42'])]);

        try {
            $llm->agent(new AgentConfig(
                provider: 'openai',
                tools: [$this->dangerousTool($ran)],
            ))->run('Delete record 42');

            $this->fail('Expected a ToolApprovalRequiredException.');
        } catch (ToolApprovalRequiredException $e) {
            $this->assertSame('delete_record', $e->pending->toolName);
            $this->assertSame(['id' => '42'], $e->pending->arguments);
            $this->assertNotEmpty($e->messages, 'the conversation travels with the exception so a caller can resume');
            $this->assertStringContainsString('approveToolCalls', $e->getMessage());
        }

        $this->assertFalse($ran, 'nothing runs without an answer');
    }

    /** A call that does not ask for approval is untouched: no approver is needed and none is consulted. */
    public function test_a_tool_that_does_not_require_approval_is_unaffected(): void
    {
        $ran = false;
        $llm = $this->makeLLM([
            $this->openAiToolCall('lookup', ['q' => 'x']),
            $this->openAiText('Found it.'),
        ]);

        $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [new ToolDefinition(
                name: 'lookup',
                description: 'Looks something up.',
                parameters: ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
                callable: function () use (&$ran): string {
                    $ran = true;

                    return 'result';
                },
            )],
        ))->run('Look it up');

        $this->assertTrue($ran);
    }

    /** Arguments are still validated for a tool that requires approval: approving garbage runs nothing. */
    public function test_an_approved_call_with_invalid_arguments_still_fails_validation(): void
    {
        $ran = false;
        $llm = $this->makeLLM([
            $this->openAiToolCall('delete_record', []),
            $this->openAiText('Which record?'),
        ]);

        $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$this->dangerousTool($ran)],
            approveToolCalls: fn (PendingApproval $pending): Decision => Decision::Approve,
        ))->run('Delete it');

        $this->assertFalse($ran, 'approval is not a substitute for validating the arguments');
    }
}
