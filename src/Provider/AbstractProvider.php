<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;

abstract class AbstractProvider implements ProviderInterface
{
    use ProviderHttpClientTrait;

    public function __construct(
        protected readonly ProviderConfig $config,
        // Test seam: a factory returning a FRESH Guzzle handler stack per request, because a
        // MockHandler queue is stateful and every request needs its own stack. `@internal` — never
        // set by production code. Trailing and optional, so `new $class($config)` in
        // ProviderFactory::make() keeps working unchanged.
        protected readonly ?\Closure $httpHandlerFactory = null,
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

    /**
     * Makes a GET request to the given endpoint path and returns the raw
     * decoded JSON response. Returns [] silently on any failure.
     *
     * @return array<string, mixed>
     */
    protected function fetchRawModels(string $endpointPath): array
    {
        try {
            return $this->httpClient(10)->get($endpointPath);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Default base URL when none is configured. */
    abstract protected function defaultBaseUrl(): string;

    abstract public function endpointPath(): string;

    abstract public function getModels(): array;
}
