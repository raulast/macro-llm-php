<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\OllamaProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for OllamaProvider — uses shared OpenAICompatible normalization.
 * Verifies identity, fixture parsing, optional auth header.
 */
class OllamaProviderTest extends TestCase
{
    private function makeProvider(?string $apiKey = null): OllamaProvider
    {
        return new OllamaProvider(new ProviderConfig(apiKey: $apiKey, defaultModel: 'llama3.2:latest'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('ollama', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from Ollama!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(12, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('ollama', $this->makeProvider()->name());
    }

    public function testBaseUrlIsLocalhost(): void
    {
        $this->assertStringContainsString('localhost', $this->makeProvider()->baseUrl());
    }

    public function testHeadersOmitAuthWhenNoKey(): void
    {
        $headers = $this->makeProvider(null)->headers();

        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testHeadersIncludeBearerWhenKeySet(): void
    {
        $headers = $this->makeProvider('local-secret')->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer local-secret', $headers['Authorization']);
    }

    public function testImplementsEmbeddingOnly(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
