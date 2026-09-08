<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\ElevenLabsProvider;
use MacroLLM\Tests\TestCase;

/**
 * Tests for ElevenLabsProvider — TTS-only AudioProviderInterface.
 * toResponse() returns a stub; provider is audio-only.
 */
class ElevenLabsProviderTest extends TestCase
{
    private function makeProvider(): ElevenLabsProvider
    {
        return new ElevenLabsProvider(new ProviderConfig(apiKey: 'xi-test-key', defaultModel: 'eleven_multilingual_v2'));
    }

    // ── Identity ─────────────────────────────────────────────────────────

    public function testName(): void
    {
        $this->assertSame('elevenlabs', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsElevenLabs(): void
    {
        $this->assertStringContainsString('elevenlabs.io', $this->makeProvider()->baseUrl());
    }

    // ── Stub toResponse returns well-formed InternalResponse ──────────────

    public function testToResponseReturnsStub(): void
    {
        $provider = $this->makeProvider();
        $response = $provider->toResponse([]);

        // ElevenLabs is TTS-only — toResponse stub returns null content with Stop
        $this->assertNull($response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    // ── parseStreamEvent stub ─────────────────────────────────────────────

    public function testParseStreamEventReturnsNull(): void
    {
        $this->assertNull($this->makeProvider()->parseStreamEvent('any raw event', 0));
    }

    // ── supportsStreaming ─────────────────────────────────────────────────

    public function testDoesNotSupportStreaming(): void
    {
        $this->assertFalse($this->makeProvider()->supportsStreaming());
    }

    // ── getModels returns known voice models ──────────────────────────────

    public function testGetModelsReturnsStaticList(): void
    {
        $models = $this->makeProvider()->getModels();

        $this->assertNotEmpty($models);
        $this->assertContains('eleven_multilingual_v2', $models);
    }

    // ── Headers use xi-api-key ────────────────────────────────────────────

    public function testHeadersUseXiApiKey(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('xi-api-key', $headers);
        $this->assertSame('xi-test-key', $headers['xi-api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // ── Capability interfaces ─────────────────────────────────────────────

    public function testImplementsAudioOnly(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(RerankingProviderInterface::class, $p);
    }
}
