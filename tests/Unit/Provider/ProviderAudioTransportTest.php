<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Provider\ElevenLabsProvider;
use MacroLLM\Provider\GroqProvider;
use MacroLLM\Tests\TestCase;

/**
 * The audio capabilities of `OpenAICapabilitiesTrait` (used by eight providers) previously constructed
 * `GuzzleHttp\Client` directly. Groq stands in for that family here: it is a real trait user, and the
 * trait's behaviour is shared, so one honest representative beats eight copies.
 */
final class ProviderAudioTransportTest extends TestCase
{
    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'macro-llm-audio-');
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param list<Response> $queue */
    private function makeGroq(array $queue, array &$history = []): GroqProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new GroqProvider(
            new ProviderConfig(apiKey: 'gsk_test', defaultModel: 'llama-3.3-70b-versatile'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    /** @param list<Response> $queue */
    private function makeElevenLabs(array $queue, array &$history = []): ElevenLabsProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new ElevenLabsProvider(
            new ProviderConfig(apiKey: 'el_test', defaultModel: 'eleven_multilingual_v2'),
            httpHandlerFactory: fn () => $stack,
        );
    }

    public function testTraitSynthesizeReturnsAudioBytesThroughTheSharedTransport(): void
    {
        $audio = "\x49\x44\x33" . '-tts';
        $history = [];
        $provider = $this->makeGroq([new Response(200, ['Content-Type' => 'audio/mpeg'], $audio)], $history);

        $response = $provider->synthesize(new AudioRequest('hola'));

        $this->assertSame($audio, $response->content);
        $this->assertSame('mp3', $response->format);
        $this->assertSame('https://api.groq.com/openai/v1/audio/speech', (string) $history[0]['request']->getUri());
    }

    public function testTraitTranscribeSendsMultipartAndOverridesTheJsonContentType(): void
    {
        $history = [];
        $provider = $this->makeGroq([new Response(200, [], '{"text":"hola"}')], $history);
        $path = $this->tempFile('AUDIO');

        try {
            $response = $provider->transcribe(new TranscriptionRequest($path));
        } finally {
            unlink($path);
        }

        $this->assertSame('hola', $response->text);
        $this->assertStringStartsWith(
            'multipart/form-data; boundary=',
            $history[0]['request']->getHeaderLine('Content-Type'),
        );
        $this->assertStringContainsString('AUDIO', (string) $history[0]['request']->getBody());
    }

    public function testTraitHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeGroq([new Response(500, [], 'boom')]);

        try {
            $provider->synthesize(new AudioRequest('hola'));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(500, $e->statusCode);
            $this->assertSame('https://api.groq.com/openai/v1', $e->endpoint);
        }
    }

    public function testElevenLabsSynthesizeReturnsAudioBytesAndKeepsItsOwnHeaders(): void
    {
        $audio = 'eleven-bytes';
        $history = [];
        $provider = $this->makeElevenLabs([new Response(200, ['Content-Type' => 'audio/mpeg'], $audio)], $history);

        $response = $provider->synthesize(new AudioRequest('hola', voice: 'voice-123'));

        $this->assertSame($audio, $response->content);
        $this->assertSame('mp3', $response->format);

        $request = $history[0]['request'];
        $this->assertSame('https://api.elevenlabs.io/v1/text-to-speech/voice-123', (string) $request->getUri());
        $this->assertSame('el_test', $request->getHeaderLine('xi-api-key'));
    }

    public function testElevenLabsHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeElevenLabs([new Response(422, [], 'unprocessable')]);

        try {
            $provider->synthesize(new AudioRequest('hola'));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(422, $e->statusCode);
            $this->assertSame('https://api.elevenlabs.io', $e->endpoint);
        }
    }
}
