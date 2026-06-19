<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class UnregisteredProviderException extends MacroLLMException
{
    public function __construct(
        public readonly string $providerName,
    ) {
        parent::__construct(
            sprintf('Provider "%s" is not registered. Register it via ProviderRegistry before use.', $providerName),
        );
    }
}
