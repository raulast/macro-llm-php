<?php

declare(strict_types=1);

namespace MacroLLM\Tool;

use Closure;

final class ToolDefinition
{
    /** @param array<string, mixed> $parameters JSON Schema object */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly Closure $callable,
    ) {}
}
