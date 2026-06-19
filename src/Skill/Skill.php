<?php

declare(strict_types=1);

namespace MacroLLM\Skill;

use MacroLLM\Config\Config;
use MacroLLM\Contract\SkillInterface;

abstract class Skill implements SkillInterface
{
    protected string $name = '';
    protected string $systemPrompt = '';

    /** @var string[] Tool names */
    protected array $tools = [];

    protected ?Config $configOverride = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    /** @return string[] */
    public function getTools(): array
    {
        return $this->tools;
    }

    public function getConfigOverride(): ?Config
    {
        return $this->configOverride;
    }

    /**
     * Hydrate from DB/JSON/array.
     *
     * @param array{name: string, system_prompt: string, tools?: string[], config?: array<string, mixed>} $data
     */
    public static function fromArray(array $data): static
    {
        $skill = new static();
        $skill->name = $data['name'];
        $skill->systemPrompt = $data['system_prompt'];
        $skill->tools = $data['tools'] ?? [];

        if (isset($data['config'])) {
            $skill->configOverride = Config::fromArray($data['config']);
        }

        return $skill;
    }

    /**
     * Simple inline factory — does not require subclassing.
     *
     * @param string[] $tools
     */
    public static function create(
        string $name,
        string $systemPrompt,
        array $tools = [],
        ?Config $config = null,
    ): static {
        $skill = new static();
        $skill->name = $name;
        $skill->systemPrompt = $systemPrompt;
        $skill->tools = $tools;
        $skill->configOverride = $config;

        return $skill;
    }
}
