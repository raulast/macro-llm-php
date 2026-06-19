<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class MCPConnectionException extends MacroLLMException
{
    public function __construct(
        public readonly string $url,
        public readonly string $detail,
    ) {
        parent::__construct(
            sprintf('MCP connection failed for "%s": %s', $url, $detail),
        );
    }
}
