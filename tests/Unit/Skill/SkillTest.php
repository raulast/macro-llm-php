<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Skill;

use MacroLLM\Config\Config;
use MacroLLM\Skill\GenericSkill;
use MacroLLM\Skill\Skill;
use MacroLLM\Tests\TestCase;

/**
 * Characterization tests for Skill and GenericSkill.
 *
 * Pin the key behavioral invariants:
 * - Skill::create() and Skill::fromArray() called on the abstract base MUST return
 *   a GenericSkill, not an attempt to instantiate the abstract class. This was a
 *   real bug fix and must stay pinned.
 * - A concrete subclass calling the same factory methods gets its own type back
 *   (late static binding works correctly).
 * - fromArray() hydrates the optional 'config' key into a Config override.
 */
final class SkillTest extends TestCase
{
    // ── Skill::create() ─────────────────────────────────────────────────────

    public function test_skill_create_on_base_class_returns_generic_skill(): void
    {
        // Bug guard: calling Skill::create() on the abstract base must NOT
        // try to instantiate Skill (which would fatal), it must return GenericSkill.
        $skill = Skill::create('my-skill', 'You are a helper.');

        $this->assertInstanceOf(GenericSkill::class, $skill);
    }

    public function test_skill_create_sets_name_and_system_prompt(): void
    {
        $skill = Skill::create('translator', 'You translate text.');

        $this->assertSame('translator', $skill->getName());
        $this->assertSame('You translate text.', $skill->getSystemPrompt());
    }

    public function test_skill_create_sets_tools_list(): void
    {
        $skill = Skill::create('researcher', 'Research things.', ['search_web', 'read_page']);

        $this->assertSame(['search_web', 'read_page'], $skill->getTools());
    }

    public function test_skill_create_with_no_tools_defaults_to_empty_array(): void
    {
        $skill = Skill::create('simple', 'Simple skill.');

        $this->assertSame([], $skill->getTools());
    }

    public function test_skill_create_accepts_config_override(): void
    {
        $config = Config::fromArray(['default_provider' => 'anthropic']);
        $skill = Skill::create('fancy', 'Fancy skill.', [], $config);

        $this->assertSame($config, $skill->getConfigOverride());
    }

    public function test_skill_create_without_config_returns_null_override(): void
    {
        $skill = Skill::create('bare', 'No config.');

        $this->assertNull($skill->getConfigOverride());
    }

    // ── Skill::create() from a concrete subclass ────────────────────────────

    public function test_skill_create_on_subclass_returns_subclass_instance(): void
    {
        // Late static binding: calling create() on a concrete subclass must
        // return an instance of that subclass, not GenericSkill.
        $skill = ConcreteSkillForTest::create('sub-skill', 'Sub system prompt.');

        $this->assertInstanceOf(ConcreteSkillForTest::class, $skill);
    }

    // ── Skill::fromArray() ──────────────────────────────────────────────────

    public function test_skill_from_array_on_base_class_returns_generic_skill(): void
    {
        // Same bug guard as for create() — must return GenericSkill, not crash.
        $skill = Skill::fromArray([
            'name'          => 'support',
            'system_prompt' => 'Help users.',
        ]);

        $this->assertInstanceOf(GenericSkill::class, $skill);
    }

    public function test_skill_from_array_sets_name_and_system_prompt(): void
    {
        $skill = Skill::fromArray([
            'name'          => 'coder',
            'system_prompt' => 'Write PHP code.',
        ]);

        $this->assertSame('coder', $skill->getName());
        $this->assertSame('Write PHP code.', $skill->getSystemPrompt());
    }

    public function test_skill_from_array_hydrates_tools(): void
    {
        $skill = Skill::fromArray([
            'name'          => 'writer',
            'system_prompt' => 'Write content.',
            'tools'         => ['search_web', 'fact_check'],
        ]);

        $this->assertSame(['search_web', 'fact_check'], $skill->getTools());
    }

    public function test_skill_from_array_without_tools_key_defaults_to_empty(): void
    {
        $skill = Skill::fromArray([
            'name'          => 'simple',
            'system_prompt' => 'Simple.',
        ]);

        $this->assertSame([], $skill->getTools());
    }

    public function test_skill_from_array_hydrates_config_key_into_config_override(): void
    {
        // The 'config' key in the data array must be hydrated into a Config
        // override via Config::fromArray(). This allows DB-stored skills to
        // carry their own provider configuration.
        $skill = Skill::fromArray([
            'name'          => 'analyst',
            'system_prompt' => 'Analyze data.',
            'config'        => [
                'default_provider' => 'gemini',
            ],
        ]);

        $config = $skill->getConfigOverride();
        $this->assertInstanceOf(Config::class, $config);
        $this->assertSame('gemini', $config->defaultProvider());
    }

    public function test_skill_from_array_without_config_key_returns_null_override(): void
    {
        $skill = Skill::fromArray([
            'name'          => 'plain',
            'system_prompt' => 'No config.',
        ]);

        $this->assertNull($skill->getConfigOverride());
    }

    public function test_skill_from_array_on_subclass_returns_subclass_instance(): void
    {
        $skill = ConcreteSkillForTest::fromArray([
            'name'          => 'concrete',
            'system_prompt' => 'I am concrete.',
        ]);

        $this->assertInstanceOf(ConcreteSkillForTest::class, $skill);
    }
}

/**
 * Concrete subclass used only in tests to verify late static binding behavior.
 */
final class ConcreteSkillForTest extends Skill
{
    // Inherits all defaults from Skill; no custom behavior needed for pinning.
}
