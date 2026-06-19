<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\UnregisteredProviderException;

final class ProviderFactory
{
    /** @var array<string, class-string<ProviderInterface>> */
    private const PROVIDER_MAP = [
        'openai' => OpenAIProvider::class,
        'anthropic' => AnthropicProvider::class,
        'gemini' => GeminiProvider::class,
        'groq' => GroqProvider::class,
        'openrouter' => OpenRouterProvider::class,
        'ollama' => OllamaProvider::class,
        'llamacpp' => LlamaCppProvider::class,
    ];

    /**
     * Create a provider instance by name.
     *
     * @throws UnregisteredProviderException If the provider name is not in the built-in map.
     */
    public static function make(string $name, ProviderConfig $config): ProviderInterface
    {
        $class = self::PROVIDER_MAP[$name] ?? null;

        if ($class === null) {
            throw new UnregisteredProviderException($name);
        }

        return new $class($config);
    }

    /**
     * Check if a provider name is supported by the factory.
     */
    public static function supports(string $name): bool
    {
        return isset(self::PROVIDER_MAP[$name]);
    }

    /**
     * Get all supported provider names.
     *
     * @return string[]
     */
    public static function supportedProviders(): array
    {
        return array_keys(self::PROVIDER_MAP);
    }
}
