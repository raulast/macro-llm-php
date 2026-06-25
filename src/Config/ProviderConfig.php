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
        public readonly ?int $timeout = null,
        public readonly ?int $retries = null,
        public readonly array $extraHeaders = [],
        public readonly ?int $retryDelayMs = null,
    ) {}
}
