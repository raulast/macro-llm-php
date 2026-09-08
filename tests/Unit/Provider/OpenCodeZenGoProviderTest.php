<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\OpenCodeZenGoProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for OpenCodeZenGoProvider — OpenAI-compatible, custom endpoint.
 */
class OpenCodeZenGoProviderTest extends TestCase
{
    private function makeProvider(): OpenCodeZenGoProvider
    {
        return new OpenCodeZenGoProvider(new ProviderConfig(apiKey: 'ocz-test', defaultModel: 'deepseek-v3-0324'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('opencode-zen-go', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from OpenCode Zen Go!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(14, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('opencode-zen-go', $this->makeProvider()->name());
    }

    public function testBaseUrlIsOpenCode(): void
    {
        $this->assertStringContainsString('opencode.ai', $this->makeProvider()->baseUrl());
    }

    public function testEndpointPath(): void
    {
        $this->assertSame('/zen/go/v1/chat/completions', $this->makeProvider()->endpointPath());
    }

    public function testHeadersUseBearer(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ocz-test', $headers['Authorization']);
    }

    public function testHasNoCapsInterfaces(): void
    {
        $p = $this->makeProvider();

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
