<?php

declare(strict_types=1);

namespace MacroLLM\Config;

final class ProviderConfig
{
    /** @param array<string, string> $extraHeaders */
    public function __construct(
        public readonly ?string $apiKey,
        public readonly string $defaultModel,
        public readonly ?string $baseUrl = null,
        public readonly int $timeout = 30,
        public readonly int $retries = 0,
        public readonly array $extraHeaders = [],
    ) {}
}
