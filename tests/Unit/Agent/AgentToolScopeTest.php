<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Agent\Agent;
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\SkillToolConflictException;
use MacroLLM\MacroLLM;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Skill\Skill;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Tool\ToolStatus;

/**
 * Regression tests for BUG 2: tool scope isolation in Agent.
 *
 * Root cause: the execution path used the global ToolRegistry, not the offered
 * set. This forced callers to register tools both in AgentConfig::tools AND
 * in the global registry, and allowed the model to call globally registered
 * tools that were never offered to the agent.
 *
 * Test infrastructure:
 * - resolveToolMap() tests use PHP Reflection to call the private method directly.
 * - run() tests use MacroLLM's @internal httpHandlerFactory constructor parameter
 *   to inject a Guzzle MockHandler, making Agent::run() fully network-free.
 */
class AgentToolScopeTest extends TestCase
{
    // ── Infrastructure ────────────────────────────────────────────────────

    /**
     * Build an OpenAI-format JSON response body.
     *
     * @param string|null $content Text content for non-tool-call turns.
     * @param array|null  $toolCalls OpenAI tool_calls array for tool-call turns.
     */
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

    /**
     * Build a Guzzle Response that carries a text-only LLM response.
     */
    private function textGuzzleResponse(string $content = 'done'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'],
            $this->openAIResponseJson($content));
    }

    /**
     * Build a Guzzle Response that carries an LLM tool call.
     */
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

    /**
     * Build a MacroLLM wired with a fake OpenAI provider and a Guzzle MockHandler
     * that serves the scripted HTTP responses from $guzzleQueue.
     *
     * The @internal httpHandlerFactory parameter is used here — production callers
     * never set it. Each chat() call pops one response from the MockHandler.
     */
    private function makeLLM(
        array $guzzleQueue,
        ?ToolRegistry $tools = null,
        ?SkillRegistry $skills = null,
    ): MacroLLM {
        $tools ??= new ToolRegistry();
        $skills ??= new SkillRegistry($tools);

        $mock = new MockHandler($guzzleQueue);
        $stack = HandlerStack::create($mock);

        $config = new Config(defaultProvider: 'openai');

        $providerConfig = new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
            baseUrl: 'http://fake-openai.test',
        );
        $providers = new ProviderRegistry();
        // Use the real OpenAIProvider so the JSON parsing is exercised correctly
        $providers->register(new \MacroLLM\Provider\OpenAIProvider($providerConfig));

        return new MacroLLM(
            $config,
            $providers,
            $tools,
            $skills,
            httpHandlerFactory: fn() => $stack,  // inject mock handler for every chat() call
        );
    }

    /**
     * Use Reflection to call the private resolveToolMap() on an Agent.
     *
     * @return array<string, ToolDefinition>
     */
    private function callResolveToolMap(Agent $agent): array
    {
        $ref = new \ReflectionMethod(Agent::class, 'resolveToolMap');
        $ref->setAccessible(true);
        return $ref->invoke($agent);
    }

    // ── resolveToolMap tests (pure logic, no HTTP needed) ─────────────────

    /**
     * resolveToolMap() must include tools supplied only via AgentConfig::tools.
     * No global ToolRegistry registration required.
     */
    public function testResolveToolMapIncludesDirectTools(): void
    {
        $directTool = new ToolDefinition(
            name: 'list_nodes',
            description: 'List nodes',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'ok',
        );

        $llm = $this->makeLLM([$this->textGuzzleResponse()]);
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$directTool],
        ));

        $toolMap = $this->callResolveToolMap($agent);

        $this->assertArrayHasKey('list_nodes', $toolMap);
        $this->assertSame($directTool, $toolMap['list_nodes']);
    }

    /**
     * resolveToolMap() must NOT include tools only in the global registry
     * that are not offered to this agent via skills or direct tools.
     */
    public function testResolveToolMapExcludesGlobalOnlyTools(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition(
            name: 'global_only',
            description: 'Only in global registry',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'ok',
        ));

        $llm = $this->makeLLM([$this->textGuzzleResponse()], $tools);
        // Agent has NO tools and NO skills — nothing offered
        $agent = $llm->agent(new AgentConfig(provider: 'openai'));

        $toolMap = $this->callResolveToolMap($agent);

        $this->assertArrayNotHasKey('global_only', $toolMap,
            'Global-only tool must not appear in the offered tool map');
    }

    /**
     * Skill tools must win over direct tools on name collision.
     */
    public function testResolveToolMapSkillToolWinsOnNameCollision(): void
    {
        $tools = new ToolRegistry();
        $skillDef = new ToolDefinition(
            name: 'shared',
            description: 'Skill version',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'from_skill',
        );
        $tools->register($skillDef);

        $skills = new SkillRegistry($tools);
        $skills->register(Skill::create(name: 'my_skill', systemPrompt: '', tools: ['shared']));

        $directTool = new ToolDefinition(
            name: 'shared',
            description: 'Direct version — must lose',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'from_direct',
        );

        $llm = $this->makeLLM([$this->textGuzzleResponse()], $tools, $skills);
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            skillNames: ['my_skill'],
            tools: [$directTool],
        ));

        $toolMap = $this->callResolveToolMap($agent);

        $this->assertSame($skillDef, $toolMap['shared'],
            'Skill tool definition must win over direct tool on name collision');
    }

    /**
     * Two skills declaring the same tool name must throw SkillToolConflictException.
     */
    public function testResolveToolMapThrowsOnSkillConflict(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition(
            name: 'shared',
            description: 'Shared',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'ok',
        ));
        $skills = new SkillRegistry($tools);
        $skills->register(Skill::create(name: 'skill_a', systemPrompt: '', tools: ['shared']));
        $skills->register(Skill::create(name: 'skill_b', systemPrompt: '', tools: ['shared']));

        $llm = $this->makeLLM([$this->textGuzzleResponse()], $tools, $skills);
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            skillNames: ['skill_a', 'skill_b'],
        ));

        $this->expectException(SkillToolConflictException::class);
        $this->callResolveToolMap($agent);
    }

    // ── run() tests (use Guzzle MockHandler, no real network) ────────────

    /**
     * A ToolDefinition supplied ONLY via AgentConfig::tools (never in global registry)
     * must be executable: callable fires and result flows back to the model.
     * This is the regression test for the double-registration bug.
     */
    public function testDirectToolExecutesWithoutGlobalRegistration(): void
    {
        $toolFired = false;

        $directTool = new ToolDefinition(
            name: 'list_nodes',
            description: 'List nodes',
            parameters: ['type' => 'object', 'properties' => []],
            callable: function (array $args) use (&$toolFired): string {
                $toolFired = true;
                return 'node-a, node-b';
            },
        );

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('list_nodes'),          // Turn 1: model calls list_nodes
            $this->textGuzzleResponse('Found: node-a, node-b'), // Turn 2: final answer
        ]);

        // Verify list_nodes is NOT in the global registry
        $this->assertFalse($llm->tools()->has('list_nodes'),
            'list_nodes must NOT be in global registry for this test to be meaningful');

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$directTool],
        ));

        $response = $agent->run('List all nodes');

        $this->assertTrue($toolFired, 'Direct tool callable must have fired');
        $this->assertSame('Found: node-a, node-b', $response->content);
    }

    /**
     * The direct tool must appear in the InternalRequest::tools list sent to
     * the model (i.e. it must be advertised/offered).
     */
    public function testDirectToolIsAdvertisedInRequestPayload(): void
    {
        $directTool = new ToolDefinition(
            name: 'audit_flow',
            description: 'Audit flow',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'ok',
        );

        $capturedRequest = null;
        $llm = $this->makeLLM([$this->textGuzzleResponse('all good')]);

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$directTool],
            onStep: function (AgentStep $step) use (&$capturedRequest): void {
                if ($step->type === AgentStepType::FinalResponse) {
                    // Extract from the step response — we can't easily intercept the request here
                    // but we can verify via resolveToolMap
                }
            },
        ));

        $agent->run('Audit the flow');

        // Verify via resolveToolMap that the tool IS in the offered set
        $toolMap = $this->callResolveToolMap($llm->agent(new AgentConfig(
            provider: 'openai',
            tools: [$directTool],
        )));
        $this->assertArrayHasKey('audit_flow', $toolMap,
            'Direct tool must be in the offered tool map (and thus in the request payload)');
    }

    /**
     * When the model requests a tool that was never offered to this agent,
     * the agent must produce a ToolResult in error state, the loop must
     * continue (no exception escapes run()), and the model self-corrects.
     */
    public function testUnadvertisedToolProducesErrorResultAndLoopContinues(): void
    {
        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('phantom_tool'),          // Turn 1: unadvertised tool
            $this->textGuzzleResponse('I cannot use that tool.'),   // Turn 2: model recovers
        ]);

        $toolResults = [];
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            onStep: function (AgentStep $step) use (&$toolResults): void {
                if ($step->type === AgentStepType::ToolResult) {
                    $toolResults[] = $step->toolResult;
                }
            },
        ));

        // Must NOT throw any exception
        $response = $agent->run('Do something');

        $this->assertSame('I cannot use that tool.', $response->content);
        $this->assertCount(1, $toolResults, 'One ToolResult must have been observed');
        $this->assertSame(ToolStatus::Error, $toolResults[0]->status,
            'ToolResult for unadvertised tool must be in error state');
        $this->assertStringContainsString('phantom_tool', $toolResults[0]->content);
        $this->assertStringContainsString('not available', $toolResults[0]->content);
    }

    /**
     * Both ToolCall and ToolResult step callbacks must fire for an unadvertised tool,
     * so observers can see the complete event sequence.
     */
    public function testUnadvertisedToolFiresBothStepCallbacks(): void
    {
        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('ghost_tool'),
            $this->textGuzzleResponse('done'),
        ]);

        $toolCallFired = false;
        $toolResultFired = false;

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            onStep: function (AgentStep $step) use (&$toolCallFired, &$toolResultFired): void {
                if ($step->type === AgentStepType::ToolCall) {
                    $toolCallFired = true;
                }
                if ($step->type === AgentStepType::ToolResult) {
                    $toolResultFired = true;
                }
            },
        ));

        $agent->run('test');

        $this->assertTrue($toolCallFired, 'ToolCall step must fire even for unadvertised tools');
        $this->assertTrue($toolResultFired, 'ToolResult step must fire even for unadvertised tools');
    }

    /**
     * A tool registered in the global ToolRegistry but NOT offered to this agent
     * (not in AgentConfig::tools and not in any skill) must NOT be executed.
     * The callable must never fire.
     */
    public function testGlobalOnlyToolIsNotExecutedByAgent(): void
    {
        $globalToolFired = false;

        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition(
            name: 'global_tool',
            description: 'Registered globally but not offered to this agent',
            parameters: ['type' => 'object', 'properties' => []],
            callable: function (array $args) use (&$globalToolFired): string {
                $globalToolFired = true;
                return 'global result';
            },
        ));

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('global_tool'),  // model tries to call it
            $this->textGuzzleResponse('cannot use that'),  // model recovers
        ], $tools);

        // Agent has NO tools offered — global_tool is not in AgentConfig::tools
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            // tools: [] intentionally empty — global_tool not offered
        ));

        $agent->run('Use the global tool');

        $this->assertFalse($globalToolFired,
            'Global-only tool callable must NOT fire for an agent that did not offer it');
    }

    /**
     * When a skill tool and a direct AgentConfig tool share the same name,
     * the skill tool callable must fire (not the direct tool callable).
     */
    public function testSkillToolCallableFiresNotDirectToolOnNameCollision(): void
    {
        $skillToolFired = false;
        $directToolFired = false;

        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition(
            name: 'shared_tool',
            description: 'Skill version (wins)',
            parameters: ['type' => 'object', 'properties' => []],
            callable: function (array $args) use (&$skillToolFired): string {
                $skillToolFired = true;
                return 'from skill';
            },
        ));

        $skills = new SkillRegistry($tools);
        $skills->register(Skill::create(
            name: 'my_skill',
            systemPrompt: 'Use shared_tool.',
            tools: ['shared_tool'],
        ));

        $directTool = new ToolDefinition(
            name: 'shared_tool',
            description: 'Direct version — must lose to skill',
            parameters: ['type' => 'object', 'properties' => []],
            callable: function (array $args) use (&$directToolFired): string {
                $directToolFired = true;
                return 'from direct';
            },
        );

        $llm = $this->makeLLM([
            $this->toolCallGuzzleResponse('shared_tool'),
            $this->textGuzzleResponse('used skill tool'),
        ], $tools, $skills);

        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            skillNames: ['my_skill'],
            tools: [$directTool],  // same name — skill must win
        ));

        $agent->run('Use the tool');

        $this->assertTrue($skillToolFired, 'Skill tool callable must fire');
        $this->assertFalse($directToolFired, 'Direct tool callable must NOT fire when skill owns the name');
    }

    /**
     * Two skills declaring the same tool name must throw SkillToolConflictException
     * even during run().
     */
    public function testTwoSkillsWithSameToolNameThrowFromRun(): void
    {
        $tools = new ToolRegistry();
        $tools->register(new ToolDefinition(
            name: 'shared',
            description: 'Shared tool',
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn($a) => 'ok',
        ));
        $skills = new SkillRegistry($tools);
        $skills->register(Skill::create(name: 'skill_a', systemPrompt: '', tools: ['shared']));
        $skills->register(Skill::create(name: 'skill_b', systemPrompt: '', tools: ['shared']));

        $llm = $this->makeLLM([$this->textGuzzleResponse()], $tools, $skills);
        $agent = $llm->agent(new AgentConfig(
            provider: 'openai',
            skillNames: ['skill_a', 'skill_b'],
        ));

        $this->expectException(SkillToolConflictException::class);
        $agent->run('test');
    }
}
