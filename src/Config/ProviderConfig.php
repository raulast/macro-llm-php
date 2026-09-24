<?php

declare(strict_types=1);

namespace MacroLLM\Config;

/**
 * Per-provider settings.
 *
 * String values are expanded through {@see EnvResolver} on construction, so a
 * configuration entry such as '${OPENAI_API_KEY}' reaches the provider as the
 * real credential. Providers read these properties directly, which is why the
 * expansion happens here rather than only in Config::get().
 *
 * Nullable numeric fields mean "not overridden" — the global Config value wins.
 */
final class ProviderConfig
{
    public readonly ?string $apiKey;
    public readonly string $defaultModel;
    public readonly ?string $baseUrl;

    /** @var array<string, string> */
    public readonly array $extraHeaders;

    /** @param array<string, string> $extraHeaders */
    /**
     * @param string[] $fallback Provider names to try, in order, when this one fails in a way another provider
     *                         might survive. Empty by default: no hop happens unless the caller asks for one.
     */
    public function __construct(
        ?string $apiKey,
        string $defaultModel,
        ?string $baseUrl = null,
        public readonly ?int $timeout = null,
        public readonly ?int $retries = null,
        array $extraHeaders = [],
        public readonly ?int $retryDelayMs = null,
        public readonly array $fallback = [],
    ) {
        $this->apiKey       = EnvResolver::resolveNullable($apiKey);
        $this->defaultModel = EnvResolver::resolve($defaultModel);
        $this->baseUrl      = EnvResolver::resolveNullable($baseUrl);
        $this->extraHeaders = EnvResolver::resolveMap($extraHeaders);
    }
}
