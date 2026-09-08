<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\MistralProvider;
use MacroLLM\Tests\TestCase;

class MistralProviderTest extends TestCase
{
    private function makeProvider(): MistralProvider
    {
        return new MistralProvider(new ProviderConfig(apiKey: 'mistral-key', defaultModel: 'mistral-large-latest'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('mistral', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from Mistral!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(12, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('mistral', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsMistral(): void
    {
        $this->assertStringContainsString('mistral.ai', $this->makeProvider()->baseUrl());
    }

    public function testHeadersUseBearer(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
    }

    public function testImplementsEmbeddingAndAudio(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
    }
}
