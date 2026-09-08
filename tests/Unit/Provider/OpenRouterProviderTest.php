<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\OpenRouterProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for OpenRouterProvider — uses shared OpenAICompatible normalization.
 * Verifies identity, fixture parsing, embedding capability.
 */
class OpenRouterProviderTest extends TestCase
{
    private function makeProvider(): OpenRouterProvider
    {
        return new OpenRouterProvider(new ProviderConfig(apiKey: 'sk-or-test', defaultModel: 'openai/gpt-4o'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('openrouter', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from OpenRouter!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(13, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('openrouter', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsOpenRouter(): void
    {
        $this->assertStringContainsString('openrouter.ai', $this->makeProvider()->baseUrl());
    }

    public function testHeadersUseBearerToken(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
    }

    public function testImplementsEmbeddingOnly(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
