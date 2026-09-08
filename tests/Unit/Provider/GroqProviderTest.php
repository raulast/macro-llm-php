<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\GroqProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for GroqProvider — uses shared OpenAICompatible normalization.
 * Focuses on per-provider identity differences and capability matrix.
 */
class GroqProviderTest extends TestCase
{
    private function makeProvider(): GroqProvider
    {
        return new GroqProvider(new ProviderConfig(apiKey: 'gsk_test', defaultModel: 'llama-3.3-70b-versatile'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('groq', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from Groq!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(12, $response->usage->totalTokens);
    }

    public function testToResponseExtraFieldPassthrough(): void
    {
        $fixture = $this->fixtureJson('groq', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // x_groq is not in known keys → should appear in extra
        $this->assertArrayHasKey('x_groq', $response->extra);
    }

    public function testName(): void
    {
        $this->assertSame('groq', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsGroq(): void
    {
        $provider = $this->makeProvider();
        $this->assertStringContainsString('groq.com', $provider->baseUrl());
    }

    public function testHeadersUseBearer(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer gsk_test', $headers['Authorization']);
    }

    public function testImplementsEmbeddingAndAudio(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(AudioProviderInterface::class, $p);
    }
}
