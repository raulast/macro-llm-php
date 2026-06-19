<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class ProviderRequestException extends MacroLLMException
{
    public function __construct(
        public readonly string $providerName,
        public readonly int $statusCode,
        public readonly string $responseBody,
    ) {
        parent::__construct(
            sprintf(
                'Provider "%s" returned HTTP %d: %s',
                $providerName,
                $statusCode,
                $responseBody,
            ),
            $statusCode,
        );
    }
}
