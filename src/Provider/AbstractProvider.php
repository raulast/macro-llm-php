<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;

abstract class AbstractProvider implements ProviderInterface
{
    public function __construct(
        protected readonly ProviderConfig $config,
    ) {}

    public function baseUrl(): string
    {
        return $this->config->baseUrl ?? $this->defaultBaseUrl();
    }

    public function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->requireApiKey(),
            'Content-Type' => 'application/json',
        ];
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Resolves the API key from config.
     * Throws MissingApiKeyException if empty/null.
     */
    protected function requireApiKey(): string
    {
        $key = $this->config->apiKey;

        if ($key === null || $key === '') {
            throw new MissingApiKeyException($this->name());
        }

        return $key;
    }

    /** Default base URL when none is configured. */
    abstract protected function defaultBaseUrl(): string;

    abstract public function endpointPath(): string;
}
