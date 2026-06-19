<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class SkillToolConflictException extends MacroLLMException
{
    public function __construct(
        public readonly string $toolName,
        public readonly string $skillA,
        public readonly string $skillB,
    ) {
        parent::__construct(
            sprintf(
                'Tool "%s" is defined by both skill "%s" and skill "%s". Tool names must be unique across composed skills.',
                $toolName,
                $skillA,
                $skillB,
            ),
        );
    }
}
