<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\UnregisteredProviderException;
use MacroLLM\Provider\AnthropicProvider;
use MacroLLM\Provider\AzureOpenAIProvider;
use MacroLLM\Provider\CohereProvider;
use MacroLLM\Provider\DeepSeekProvider;
use MacroLLM\Provider\ElevenLabsProvider;
use MacroLLM\Provider\GeminiProvider;
use MacroLLM\Provider\GroqProvider;
use MacroLLM\Provider\LlamaCppProvider;
use MacroLLM\Provider\MistralProvider;
use MacroLLM\Provider\OllamaProvider;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Provider\OpenCodeZenGoAnthropicProvider;
use MacroLLM\Provider\OpenCodeZenGoProvider;
use MacroLLM\Provider\OpenRouterProvider;
use MacroLLM\Provider\ProviderFactory;
use MacroLLM\Provider\XAIProvider;
use MacroLLM\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for ProviderFactory: all 15 providers instantiated by name,
 * correct class returned, and unregistered name throws.
 */
class ProviderFactoryTest extends TestCase
{
    private function makeConfig(string $name): ProviderConfig
    {
        // Azure needs extraHeaders to construct properly
        if ($name === 'azure') {
            return new ProviderConfig(
                apiKey: 'test-key',
                defaultModel: 'gpt-4o',
                extraHeaders: ['resource' => 'r', 'deployment' => 'd', 'api_version' => '2024-02-01'],
            );
        }
        return new ProviderConfig(apiKey: 'test-key', defaultModel: 'test-model');
    }

    #[DataProvider('allProvidersProvider')]
    public function testMakeReturnsCorrectClass(string $name, string $expectedClass): void
    {
        $provider = ProviderFactory::make($name, $this->makeConfig($name));

        $this->assertInstanceOf($expectedClass, $provider);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
        $this->assertSame($name, $provider->name());
    }

    public static function allProvidersProvider(): array
    {
        return [
            'openai'                    => ['openai',                    OpenAIProvider::class],
            'anthropic'                 => ['anthropic',                 AnthropicProvider::class],
            'gemini'                    => ['gemini',                    GeminiProvider::class],
            'groq'                      => ['groq',                      GroqProvider::class],
            'openrouter'                => ['openrouter',                OpenRouterProvider::class],
            'ollama'                    => ['ollama',                    OllamaProvider::class],
            'llamacpp'                  => ['llamacpp',                  LlamaCppProvider::class],
            'mistral'                   => ['mistral',                   MistralProvider::class],
            'deepseek'                  => ['deepseek',                  DeepSeekProvider::class],
            'xai'                       => ['xai',                       XAIProvider::class],
            'azure'                     => ['azure',                     AzureOpenAIProvider::class],
            'cohere'                    => ['cohere',                    CohereProvider::class],
            'elevenlabs'                => ['elevenlabs',                ElevenLabsProvider::class],
            'opencode-zen-go'           => ['opencode-zen-go',           OpenCodeZenGoProvider::class],
            'opencode-zen-go-anthropic' => ['opencode-zen-go-anthropic', OpenCodeZenGoAnthropicProvider::class],
        ];
    }

    public function testMakeThrowsForUnknownProvider(): void
    {
        $this->expectException(UnregisteredProviderException::class);

        ProviderFactory::make('nonexistent', new ProviderConfig(apiKey: 'k', defaultModel: 'm'));
    }

    public function testSupportsReturnsTrueForAllKnown(): void
    {
        foreach (array_keys(self::allProvidersProvider()) as $name) {
            $this->assertTrue(
                ProviderFactory::supports($name),
                "ProviderFactory::supports() returned false for '{$name}'",
            );
        }
    }

    public function testSupportsReturnsFalseForUnknown(): void
    {
        $this->assertFalse(ProviderFactory::supports('nonexistent'));
    }

    public function testSupportedProvidersReturnsAll15(): void
    {
        $providers = ProviderFactory::supportedProviders();

        $this->assertCount(15, $providers);
        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertContains('elevenlabs', $providers);
    }
}
