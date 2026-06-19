<?php

declare(strict_types=1);

namespace MacroLLM\Registry;

use MacroLLM\Exception\ToolNotFoundException;
use MacroLLM\Tool\ToolDefinition;

final class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    public function register(ToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * @throws ToolNotFoundException
     */
    public function get(string $name): ToolDefinition
    {
        if (!$this->has($name)) {
            throw new ToolNotFoundException($name);
        }

        return $this->tools[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return array<string, ToolDefinition> */
    public function all(): array
    {
        return $this->tools;
    }
}
