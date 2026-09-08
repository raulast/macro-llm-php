<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\OpenCodeZenGoAnthropicProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for OpenCodeZenGoAnthropicProvider — Anthropic-compatible, custom endpoint.
 */
class OpenCodeZenGoAnthropicProviderTest extends TestCase
{
    private function makeProvider(): OpenCodeZenGoAnthropicProvider
    {
        return new OpenCodeZenGoAnthropicProvider(new ProviderConfig(apiKey: 'ocz-test', defaultModel: 'MiniMax-M3'));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('opencode-zen-go-anthropic', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from OpenCode Zen Go Anthropic-compatible!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        // input=10, output=8 → total=18
        $this->assertSame(18, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('opencode-zen-go-anthropic', $this->makeProvider()->name());
    }

    public function testBaseUrlIsOpenCode(): void
    {
        $this->assertStringContainsString('opencode.ai', $this->makeProvider()->baseUrl());
    }

    public function testEndpointPath(): void
    {
        $this->assertSame('/zen/go/v1/messages', $this->makeProvider()->endpointPath());
    }

    public function testHeadersUseXApiKey(): void
    {
        $headers = $this->makeProvider()->headers();

        // Inherits from AnthropicProvider — x-api-key header
        $this->assertArrayHasKey('x-api-key', $headers);
        $this->assertSame('ocz-test', $headers['x-api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testHasNoCapsInterfaces(): void
    {
        $p = $this->makeProvider();

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
