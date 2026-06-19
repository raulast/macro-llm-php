<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class ToolNotFoundException extends MacroLLMException
{
    public function __construct(
        public readonly string $toolName,
    ) {
        parent::__construct(
            sprintf('Tool "%s" not found in the ToolRegistry.', $toolName),
        );
    }
}
