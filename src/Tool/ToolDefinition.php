<?php

declare(strict_types=1);

namespace MacroLLM\Tool;

use Closure;

final class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters       JSON Schema object
     * @param  bool                  $requiresApproval  Whether a human must approve each call before it runs. The
     *                                                  tool declares its own risk, because the tool is what knows
     *                                                  whether it deletes something.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly Closure $callable,
        public readonly bool $requiresApproval = false,
    ) {}
}
