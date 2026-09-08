<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\CohereProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for CohereProvider — native /v2/chat format (not OpenAI-compatible).
 */
class CohereProviderTest extends TestCase
{
    private function makeProvider(): CohereProvider
    {
        return new CohereProvider(new ProviderConfig(apiKey: 'cohere-key', defaultModel: 'command-r-plus'));
    }

    // ── toResponse ─────────────────────────────────────────────────────────

    public function testToResponseParsesTextContent(): void
    {
        $fixture = $this->fixtureJson('cohere', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from Cohere!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    public function testToResponseMapsBilledUnitsToUsage(): void
    {
        $fixture = $this->fixtureJson('cohere', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // Cohere billed_units: input_tokens=8, output_tokens=4 → total=12
        $this->assertSame(8, $response->usage->promptTokens);
        $this->assertSame(4, $response->usage->completionTokens);
        $this->assertSame(12, $response->usage->totalTokens);
    }

    public function testToResponseHandlesMissingUsage(): void
    {
        $fixture = [
            'message' => [
                'role'    => 'assistant',
                'content' => [['type' => 'text', 'text' => 'hi']],
            ],
        ];
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame(0, $response->usage->totalTokens);
    }

    // ── parseStreamEvent ──────────────────────────────────────────────────

    public function testParseStreamEventContentDeltaReturnsDelta(): void
    {
        $provider = $this->makeProvider();
        $raw = '{"type":"content-delta","index":0,"delta":{"type":"text-generation","message":{"content":{"type":"text","text":"Hello"}}}}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertSame('Hello', $chunk->delta);
        $this->assertFalse($chunk->finished);
    }

    public function testParseStreamEventMessageEndReturnsFinished(): void
    {
        $provider = $this->makeProvider();
        $raw = '{"type":"message-end","delta":{"finish_reason":"COMPLETE"}}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertTrue($chunk->finished);
    }

    public function testParseStreamEventInvalidJsonReturnsNull(): void
    {
        $this->assertNull($this->makeProvider()->parseStreamEvent('not json', 0));
    }

    public function testParseStreamEventUnknownTypeReturnsNull(): void
    {
        $raw = '{"type":"unknown-type","data":{}}';
        $this->assertNull($this->makeProvider()->parseStreamEvent($raw, 0));
    }

    // ── Identity ─────────────────────────────────────────────────────────

    public function testName(): void
    {
        $this->assertSame('cohere', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsCohere(): void
    {
        $this->assertStringContainsString('cohere.com', $this->makeProvider()->baseUrl());
    }

    // ── Capability interfaces ─────────────────────────────────────────────

    public function testImplementsEmbeddingAndReranking(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(RerankingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
