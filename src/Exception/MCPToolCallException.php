<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class MCPToolCallException extends MacroLLMException
{
    public function __construct(
        public readonly string $toolName,
        public readonly int $errorCode,
        public readonly string $errorMessage,
    ) {
        parent::__construct(
            sprintf('MCP tool call "%s" failed [%d]: %s', $toolName, $errorCode, $errorMessage),
            $errorCode,
        );
    }
}
