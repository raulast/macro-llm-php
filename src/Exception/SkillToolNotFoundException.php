<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class SkillToolNotFoundException extends MacroLLMException
{
    public function __construct(
        public readonly string $skillName,
        public readonly string $toolName,
    ) {
        parent::__construct(
            sprintf('Skill "%s" references tool "%s" which is not registered in the ToolRegistry.', $skillName, $toolName),
        );
    }
}
