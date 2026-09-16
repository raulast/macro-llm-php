<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Registry;

use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Contract\SkillInterface;
use MacroLLM\Exception\SkillToolNotFoundException;
use MacroLLM\Exception\ToolNotFoundException;
use MacroLLM\Exception\UnregisteredProviderException;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Config\Config;
use MacroLLM\Registry\ProviderRegistry;
use MacroLLM\Registry\SkillRegistry;
use MacroLLM\Registry\ToolRegistry;
use MacroLLM\Skill\Skill;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;

/**
 * Characterization tests for ToolRegistry, ProviderRegistry, and SkillRegistry.
 */
final class RegistryTest extends TestCase
{
    // ── ToolRegistry ────────────────────────────────────────────────────────

    public function test_tool_registry_register_and_has(): void
    {
        $registry = new ToolRegistry();
        $tool = $this->makeTool('ping');

        $registry->register($tool);

        $this->assertTrue($registry->has('ping'));
        $this->assertFalse($registry->has('pong'));
    }

    public function test_tool_registry_get_returns_registered_tool(): void
    {
        $registry = new ToolRegistry();
        $tool = $this->makeTool('get_weather');
        $registry->register($tool);

        $found = $registry->get('get_weather');

        $this->assertSame($tool, $found);
    }

    public function test_tool_registry_get_throws_for_unknown_name(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(ToolNotFoundException::class);
        $registry->get('nonexistent');
    }

    public function test_tool_registry_all_returns_all_registered_tools(): void
    {
        $registry = new ToolRegistry();
        $a = $this->makeTool('tool_a');
        $b = $this->makeTool('tool_b');
        $registry->register($a);
        $registry->register($b);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('tool_a', $all);
        $this->assertArrayHasKey('tool_b', $all);
    }

    public function test_tool_registry_all_is_empty_when_nothing_registered(): void
    {
        $registry = new ToolRegistry();

        $this->assertSame([], $registry->all());
    }

    public function test_tool_registry_registering_same_name_replaces_previous(): void
    {
        // ToolRegistry does not throw on duplicate — it silently replaces.
        $registry = new ToolRegistry();
        $original = $this->makeTool('search');
        $replacement = $this->makeTool('search');

        $registry->register($original);
        $registry->register($replacement);

        $this->assertSame($replacement, $registry->get('search'));
    }

    // ── ProviderRegistry ────────────────────────────────────────────────────

    public function test_provider_registry_register_and_has(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeProvider('openai');

        $registry->register($provider);

        $this->assertTrue($registry->has('openai'));
        $this->assertFalse($registry->has('anthropic'));
    }

    public function test_provider_registry_get_returns_registered_provider(): void
    {
        $registry = new ProviderRegistry();
        $provider = $this->makeProvider('groq');
        $registry->register($provider);

        $found = $registry->get('groq');

        $this->assertSame($provider, $found);
    }

    public function test_provider_registry_get_throws_for_unknown_provider(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(UnregisteredProviderException::class);
        $registry->get('mystery-provider');
    }

    public function test_provider_registry_registering_same_name_replaces_rather_than_throws(): void
    {
        // DOCUMENTED INVARIANT: duplicate registration replaces silently.
        // This is Req 1.7 in the codebase. Pin it.
        $registry = new ProviderRegistry();
        $original = $this->makeProvider('openai');
        $replacement = $this->makeProvider('openai');

        $registry->register($original);
        $registry->register($replacement);

        $this->assertSame($replacement, $registry->get('openai'));
        $this->assertCount(1, $registry->all());
    }

    public function test_provider_registry_on_register_listener_fires_after_registration(): void
    {
        $registry = new ProviderRegistry();
        $recorded = [];

        $registry->onRegister(function (string $name) use (&$recorded): void {
            $recorded[] = $name;
        });

        $registry->register($this->makeProvider('openai'));
        $registry->register($this->makeProvider('anthropic'));

        $this->assertSame(['openai', 'anthropic'], $recorded);
    }

    public function test_provider_registry_on_register_listener_fires_on_replacement_too(): void
    {
        $registry = new ProviderRegistry();
        $callCount = 0;

        $registry->onRegister(function () use (&$callCount): void {
            $callCount++;
        });

        $registry->register($this->makeProvider('openai'));
        $registry->register($this->makeProvider('openai')); // replacement

        $this->assertSame(2, $callCount);
    }

    public function test_provider_registry_multiple_listeners_all_fire(): void
    {
        $registry = new ProviderRegistry();
        $log = [];

        // Arrow functions capture by VALUE, so mutations inside them don't
        // update $log in the outer scope. Use explicit by-reference closures.
        $registry->onRegister(function (string $n) use (&$log): void {
            $log[] = "listener-1:{$n}";
        });
        $registry->onRegister(function (string $n) use (&$log): void {
            $log[] = "listener-2:{$n}";
        });

        $registry->register($this->makeProvider('gemini'));

        $this->assertSame(['listener-1:gemini', 'listener-2:gemini'], $log);
    }

    public function test_provider_registry_all_returns_keyed_by_provider_name(): void
    {
        $registry = new ProviderRegistry();
        $registry->register($this->makeProvider('openai'));
        $registry->register($this->makeProvider('anthropic'));

        $all = $registry->all();

        $this->assertArrayHasKey('openai', $all);
        $this->assertArrayHasKey('anthropic', $all);
    }

    // ── SkillRegistry ────────────────────────────────────────────────────────

    public function test_skill_registry_register_succeeds_when_all_tools_exist(): void
    {
        $tools = new ToolRegistry();
        $tools->register($this->makeTool('search_web'));
        $registry = new SkillRegistry($tools);

        $skill = Skill::create('researcher', 'Research.', ['search_web']);
        $registry->register($skill);

        $this->assertTrue($registry->has('researcher'));
    }

    public function test_skill_registry_register_throws_when_tool_is_missing(): void
    {
        // Fail-fast: registering a skill whose tool is NOT in ToolRegistry must
        // throw immediately, not at invocation time.
        $tools = new ToolRegistry(); // empty — no tools registered
        $registry = new SkillRegistry($tools);

        $skill = Skill::create('ghost', 'Ghost skill.', ['nonexistent_tool']);

        $this->expectException(SkillToolNotFoundException::class);
        $registry->register($skill);
    }

    public function test_skill_registry_register_throws_with_correct_skill_and_tool_name(): void
    {
        $tools = new ToolRegistry();
        $registry = new SkillRegistry($tools);

        $skill = Skill::create('my-skill', 'Prompt.', ['missing_tool']);

        try {
            $registry->register($skill);
            $this->fail('Expected SkillToolNotFoundException was not thrown.');
        } catch (SkillToolNotFoundException $e) {
            $this->assertSame('my-skill', $e->skillName);
            $this->assertSame('missing_tool', $e->toolName);
        }
    }

    public function test_skill_registry_register_skill_with_no_tools_succeeds(): void
    {
        $tools = new ToolRegistry();
        $registry = new SkillRegistry($tools);

        // Skills with empty tool lists should register without checking ToolRegistry
        $skill = Skill::create('no-tools', 'Pure prompt skill.', []);
        $registry->register($skill);

        $this->assertTrue($registry->has('no-tools'));
    }

    public function test_skill_registry_get_returns_registered_skill(): void
    {
        $tools = new ToolRegistry();
        $registry = new SkillRegistry($tools);

        $skill = Skill::create('coder', 'Write code.');
        $registry->register($skill);

        $found = $registry->get('coder');
        $this->assertSame($skill, $found);
    }

    public function test_skill_registry_has_returns_false_for_unknown(): void
    {
        $registry = new SkillRegistry(new ToolRegistry());

        $this->assertFalse($registry->has('unknown'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeTool(string $name): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: "Tool {$name}",
            parameters: ['type' => 'object', 'properties' => []],
            callable: fn(array $args): string => 'ok',
        );
    }

    private function makeProvider(string $name): ProviderInterface
    {
        return new class($name) implements ProviderInterface {
            public function __construct(private string $n) {}

            public function name(): string         { return $this->n; }
            public function baseUrl(): string      { return 'https://example.com'; }
            public function endpointPath(): string { return '/chat'; }
            public function headers(): array       { return []; }
            public function supportsStreaming(): bool { return false; }
            public function getModels(): array     { return []; }

            public function toPayload(InternalRequest $req): array  { return []; }
            public function toResponse(array $res): InternalResponse
            {
                return new InternalResponse(
                    content: null,
                    finishReason: \MacroLLM\Message\FinishReason::Stop,
                );
            }
            public function parseStreamEvent(string $event, int $idx): ?StreamChunk { return null; }
        };
    }
}
