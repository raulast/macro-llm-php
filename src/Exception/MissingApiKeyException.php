<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class MissingApiKeyException extends MacroLLMException
{
    public function __construct(
        public readonly string $providerName,
    ) {
        parent::__construct(
            sprintf('Missing API key for provider "%s". Configure it in your macro-llm config or environment.', $providerName),
        );
    }
}
