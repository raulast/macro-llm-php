<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\DeepSeekProvider;
use MacroLLM\Tests\TestCase;

class DeepSeekProviderTest extends TestCase
{
    private function makeProvider(): DeepSeekProvider
    {
        return new DeepSeekProvider(new ProviderConfig(apiKey: 'deepseek-key', defaultModel: 'deepseek-chat'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('deepseek', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from DeepSeek!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(12, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('deepseek', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsDeepSeek(): void
    {
        $this->assertStringContainsString('deepseek.com', $this->makeProvider()->baseUrl());
    }

    public function testGetModelsReturnsStaticList(): void
    {
        $models = $this->makeProvider()->getModels();

        $this->assertNotEmpty($models);
        $this->assertContains('deepseek-chat', $models);
        $this->assertContains('deepseek-reasoner', $models);
    }

    public function testHasNoCapsInterfaces(): void
    {
        $p = $this->makeProvider();

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
