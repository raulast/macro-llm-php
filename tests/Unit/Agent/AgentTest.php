<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\MaxToolIterationsException;
use MacroLLM\MacroLLM;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;

final class AgentTest extends TestCase
{
    // ── Infrastructure ────────────────────────────────────────────────────

    private function openAIResponseJson(
        ?string $content,
        ?array $toolCalls = null,
    ): string {
        $message = ['role' => 'assistant', 'content' => $content];
        $finishReason = 'stop';

        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
            $finishReason = 'tool_calls';
        }

        return json_encode([
            'id'      => 'chatcmpl-test',
            'object'  => 'chat.completion',
            'model'   => 'gpt-4o',
            'choices' => [[
                'index'         => 0,
                'message'       => $message,
                'finish_reason' => $finishReason,
            ]],
            'usage'   => [
                'prompt_tokens'     => 10,
                'completion_tokens' => 5,
                'total_tokens'      => 15,
            ],
        ]);
    }

    private function textGuzzleResponse(string $content = 'done'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'],
            $this->openAIResponseJson($content));
    }

    private function toolCallGuzzleResponse(
        string $toolName,
        array $args = [],
        string $id = 'tc_1',
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'],
            $this->openAIResponseJson(null, [[
                'id'       => $id,
                'type'     => 'function',
                'function' => [
                    'name'      => $toolName,
                    'arguments' => json_encode($args ?: new \stdClass()),
                ],
            ]]));
    }

    private function makeLLM(array $guzzleQueue): MacroLLM
    {
        $tools = new ToolRegistry();
        $skills = new SkillRegistry($tools);

        $mock = new MockHandler($guzzleQueue);
        $stack = HandlerStack::create($mock);

        $config = new Config(defaultProvider: 'openai');

        $providerConfig = new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://fake-openai.test',
        );
        $providers = new ProviderRegistry();
        $providers->register(new \MacroLLM\Provider\OpenAIProvider($providerConfig));

        return new MacroLLM(
            $config,
            $providers,
            $tools,
            $skills,
            httpHandlerFactory: fn() => $stack,
        );
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    public function test_run_returns_final_content_without_tools(): void
    {
        $llm = $this->makeLLM([
            $this->textGuzzleResponse('Hello, world!'),
        ]);

        $agent = $llm->agent(new AgentConfig(provider: 'openai'));
        $response = $agent->run('Hi');

        $this->assertSame('Hello, world!', $response->content);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test_run_handles_one_tool_iteration(): void
    {
        $toolExecuted = false;
        $toolDef = new ToolDefinition(
            name: 'get_weather',
            description: 'Get weather',
            parameters: ['type' => 'object', 'properties' => []],
            callable: function (array $args) use (&$toolExecuted): string {
                $toolExecuted = true;
                return 'Sunny, 25C';
            },
        );

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('get_weather'),
            $this->textGuzzleResponse('The weather is Sunny, 25C.'),
        ]);

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$toolDef],
        ));

        $response = $agent->run('What is the weather?');

        $this->assertTrue($toolExecuted, 'Tool must be executed');
        $this->assertSame('The weather is Sunny, 25C.', $response->content);
    }

    public function test_max_iterations_exhaustion_throws_exception_with_last_response(): void
    {
        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('loop_tool', [], 'tc_1'),
            $this->toolCallGuzzleResponse('loop_tool', [], 'tc_2'),
        ]);

        $toolDef = new ToolDefinition(
            name: 'loop_tool',
            description: 'Loop tool',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn() => 'result',
        );

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$toolDef],
            maxIterations: 1,
        ));

        $this->expectException(MaxToolIterationsException::class);

        try {
            $agent->run('Run forever');
        } catch (MaxToolIterationsException $e) {
            $this->assertNotNull($e->lastResponse);
            $this->assertTrue($e->lastResponse->hasToolCalls());
            throw $e;
        }
    }

    public function test_on_step_ordering_and_step_types(): void
    {
        $toolDef = new ToolDefinition(
            name: 'echo_tool',
            description: 'Echo',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn() => 'echo_result',
        );

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('echo_tool'),
            $this->textGuzzleResponse('Done with tool.'),
        ]);

        $steps = [];
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$toolDef],
            onStep: function (AgentStep $step) use (&$steps): void {
                $steps[] = $step;
            },
        ));

        $agent->run('Echo this');

        $this->assertNotEmpty($steps);

        $types = array_map(fn(AgentStep $s) => $s->type, $steps);
        $this->assertSame([
            AgentStepType::LlmResponse,
            AgentStepType::ToolCall,
            AgentStepType::ToolResult,
            AgentStepType::FinalResponse,
        ], $types);
    }

    public function test_agent_step_iteration_coherence(): void
    {
        $toolDef = new ToolDefinition(
            name: 'test_tool',
            description: 'Test',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn() => 'ok',
        );

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('test_tool'),
            $this->textGuzzleResponse('Finished.'),
        ]);

        $iterationsObserved = [];
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$toolDef],
            onStep: function (AgentStep $step) use (&$iterationsObserved): void {
                $iterationsObserved[] = [$step->type, $step->iteration];
            },
        ));

        $agent->run('Go');

        $this->assertSame(1, $iterationsObserved[0][1]); // LlmResponse
        $this->assertSame(1, $iterationsObserved[1][1]); // ToolCall
        $this->assertSame(1, $iterationsObserved[2][1]); // ToolResult
        $this->assertSame(2, $iterationsObserved[3][1]); // FinalResponse
    }
}
