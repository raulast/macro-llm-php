<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\LlamaCppProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for LlamaCppProvider — uses shared OpenAICompatible normalization.
 * No auth header; embedding only.
 */
class LlamaCppProviderTest extends TestCase
{
    private function makeProvider(): LlamaCppProvider
    {
        return new LlamaCppProvider(new ProviderConfig(apiKey: null, defaultModel: 'llama-3.2'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('llamacpp', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from llama.cpp!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(13, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('llamacpp', $this->makeProvider()->name());
    }

    public function testBaseUrlIsLocalhost(): void
    {
        $this->assertStringContainsString('localhost', $this->makeProvider()->baseUrl());
    }

    public function testHeadersHaveNoAuth(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
    }

    public function testImplementsEmbeddingOnly(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
