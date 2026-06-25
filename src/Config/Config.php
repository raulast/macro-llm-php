<?php

declare(strict_types=1);

namespace MacroLLM\Config;

final class Config
{
    /** @param array<string, ProviderConfig> $providers */
    public function __construct(
        private array $providers = [],
        private ?string $defaultProvider = null,
        private int $timeout = 30,
        private int $retries = 0,
        private int $retryDelayMs = 500,
        private int $maxToolIterations = 10,
    ) {}

    public function defaultProvider(): ?string
    {
        return $this->defaultProvider;
    }

    public function provider(string $name): ?ProviderConfig
    {
        return $this->providers[$name] ?? null;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function retries(): int
    {
        return $this->retries;
    }

    public function retryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    public function maxToolIterations(): int
    {
        return $this->maxToolIterations;
    }

    public function get(string $key): mixed
    {
        $value = $this->resolveKey($key);

        if (is_string($value)) {
            return $this->resolveEnvVars($value);
        }

        return $value;
    }

    /**
     * Per-request override merge — only non-null override values win.
     * Fixes the sentinel anti-pattern: null = "not set", not "default value".
     */
    public function mergedWith(?self $override): self
    {
        if ($override === null) {
            return $this;
        }

        return new self(
            providers: array_merge($this->providers, $override->providers),
            defaultProvider: $override->defaultProvider ?? $this->defaultProvider,
            timeout: $override->timeout !== 30 ? $override->timeout : $this->timeout,
            retries: $override->retries !== 0 ? $override->retries : $this->retries,
            retryDelayMs: $override->retryDelayMs !== 500 ? $override->retryDelayMs : $this->retryDelayMs,
            maxToolIterations: $override->maxToolIterations !== 10 ? $override->maxToolIterations : $this->maxToolIterations,
        );
    }

    public static function fromArray(array $data): self
    {
        $providers = [];

        foreach ($data['providers'] ?? [] as $name => $config) {
            $providers[$name] = new ProviderConfig(
                apiKey: $config['api_key'] ?? null,
                defaultModel: $config['default_model'] ?? 'default',
                baseUrl: $config['base_url'] ?? null,
                timeout: isset($config['timeout']) ? (int) $config['timeout'] : null,
                retries: isset($config['retries']) ? (int) $config['retries'] : null,
                extraHeaders: $config['extra_headers'] ?? [],
                retryDelayMs: isset($config['retry_delay_ms']) ? (int) $config['retry_delay_ms'] : null,
            );
        }

        return new self(
            providers: $providers,
            defaultProvider: $data['default_provider'] ?? null,
            timeout: $data['timeout'] ?? 30,
            retries: $data['retries'] ?? 0,
            retryDelayMs: $data['retry_delay_ms'] ?? 500,
            maxToolIterations: $data['max_tool_iterations'] ?? 10,
        );
    }

    private function resolveKey(string $key): mixed
    {
        $map = [
            'default_provider'    => $this->defaultProvider,
            'timeout'             => $this->timeout,
            'retries'             => $this->retries,
            'retry_delay_ms'      => $this->retryDelayMs,
            'max_tool_iterations' => $this->maxToolIterations,
        ];

        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        if (str_starts_with($key, 'providers.')) {
            $parts = explode('.', $key, 3);

            if (count($parts) < 2) {
                return null;
            }

            $providerName = $parts[1];
            $provider = $this->providers[$providerName] ?? null;

            if ($provider === null) {
                return null;
            }

            if (count($parts) === 2) {
                return $provider;
            }

            return match ($parts[2]) {
                'api_key'        => $provider->apiKey,
                'default_model'  => $provider->defaultModel,
                'base_url'       => $provider->baseUrl,
                'timeout'        => $provider->timeout,
                'retries'        => $provider->retries,
                'extra_headers'  => $provider->extraHeaders,
                'retry_delay_ms' => $provider->retryDelayMs,
                default          => null,
            };
        }

        return null;
    }

    private function resolveEnvVars(string $value): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Z0-9_]+)\}/',
            static function (array $matches): string {
                $varName = $matches[1];
                return $_ENV[$varName]
                    ?? (getenv($varName) !== false ? getenv($varName) : $matches[0]);
            },
            $value,
        );
    }
}
