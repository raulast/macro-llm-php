<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\XAIProvider;
use MacroLLM\Tests\TestCase;

class XAIProviderTest extends TestCase
{
    private function makeProvider(): XAIProvider
    {
        return new XAIProvider(new ProviderConfig(apiKey: 'xai-key', defaultModel: 'grok-beta'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('xai', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from xAI Grok!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(13, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('xai', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsXai(): void
    {
        $this->assertStringContainsString('x.ai', $this->makeProvider()->baseUrl());
    }

    public function testImplementsEmbeddingAndImage(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
