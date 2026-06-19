<?php

declare(strict_types=1);

namespace MacroLLM\Registry;

use MacroLLM\Contract\SkillInterface;
use MacroLLM\Exception\SkillToolNotFoundException;

final class SkillRegistry
{
    /** @var array<string, SkillInterface> */
    private array $skills = [];

    public function __construct(
        private readonly ToolRegistry $tools,
    ) {}

    /**
     * Register a skill after validating all its referenced tools exist.
     * Throws SkillToolNotFoundException on first missing tool (fail-fast).
     *
     * @throws SkillToolNotFoundException
     */
    public function register(SkillInterface $skill): void
    {
        foreach ($skill->getTools() as $toolName) {
            if (!$this->tools->has($toolName)) {
                throw new SkillToolNotFoundException($skill->getName(), $toolName);
            }
        }

        $this->skills[$skill->getName()] = $skill;
    }

    public function get(string $name): SkillInterface
    {
        if (!$this->has($name)) {
            throw new \RuntimeException(sprintf('Skill "%s" is not registered.', $name));
        }

        return $this->skills[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }
}
