<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Config\Config;

interface SkillInterface
{
    public function getName(): string;

    public function getSystemPrompt(): string;

    /** @return string[] Tool names resolved from ToolRegistry */
    public function getTools(): array;

    public function getConfigOverride(): ?Config;
}
