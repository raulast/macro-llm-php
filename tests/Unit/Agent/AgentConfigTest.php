<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent;

use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\NullMemory;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;

final class AgentConfigTest extends TestCase
{
    // ── Defaults ────────────────────────────────────────────────────────────

    public function test_default_values(): void
    {
        $config = new AgentConfig();

        $this->assertNull($config->provider);
        $this->assertNull($config->systemPrompt);
        $this->assertSame([], $config->tools);
        $this->assertSame([], $config->skillNames);
        $this->assertSame("\n\n", $config->skillSeparator);
        $this->assertSame(10, $config->maxIterations);
        $this->assertInstanceOf(NullMemory::class, $config->memory);
        $this->assertNull($config->onStep);
    }

    // ── Custom Values ───────────────────────────────────────────────────────

    public function test_custom_values(): void
    {
        $tool = new ToolDefinition(
            name: 'test_tool',
            description: 'A test tool',
            parameters: ['type' => 'object'],
            callable: fn() => 'ok',
        );

        $memory = new NullMemory();
        $callback = fn($step) => null;

        $config = new AgentConfig(
            provider: 'anthropic',
            systemPrompt: 'You are an assistant.',
            tools: [$tool],
            skillNames: ['skill_one'],
            skillSeparator: '---',
            maxIterations: 5,
            memory: $memory,
            onStep: $callback,
        );

        $this->assertSame('anthropic', $config->provider);
        $this->assertSame('You are an assistant.', $config->systemPrompt);
        $this->assertSame([$tool], $config->tools);
        $this->assertSame(['skill_one'], $config->skillNames);
        $this->assertSame('---', $config->skillSeparator);
        $this->assertSame(5, $config->maxIterations);
        $this->assertSame($memory, $config->memory);
        $this->assertSame($callback, $config->onStep);
    }
}
