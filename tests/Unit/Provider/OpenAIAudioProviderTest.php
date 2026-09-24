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
use MacroLLM\Provider\OpenAIAudioProvider;
use MacroLLM\Tests\TestCase;

/**
 * The audio provider used to construct its own `GuzzleHttp\Client` for both calls, which cost it the
 * retry policy, the shared failure contract and any way to test it offline. These tests exist because
 * the `@internal` handler seam now reaches it.
 *
 * @phpstan-type History array<int, array{request: \Psr\Http\Message\RequestInterface}>
 */
final class OpenAIAudioProviderTest extends TestCase
{
    /**
     * @param  list<Response|callable>  $queue
     * @param  History  $history
     */
    private function makeProvider(array $queue, array &$history = [], ?int $retries = null): OpenAIAudioProvider
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new OpenAIAudioProvider(
            new ProviderConfig(apiKey: 'sk-test', defaultModel: 'gpt-4o', retries: $retries),
            httpHandlerFactory: fn () => $stack,
        );
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'macro-llm-audio-');
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Reads a request body the way a transport does — from wherever it is, to the end.
     *
     * NOT `(string) $request->getBody()`: that cast seeks to 0 before reading (`Stream::__toString()`)
     * and would therefore rewind the multipart file handles, repairing the very defect the retry test
     * below exists to detect.
     *
     * @param  list<string>  $bodies
     * @return callable(\Psr\Http\Message\RequestInterface): Response
     */
    private function captureBody(array &$bodies, int $status, string $body = ''): callable
    {
        return function ($request) use (&$bodies, $status, $body): Response {
            $stream = $request->getBody();
            $captured = '';

            while (! $stream->eof()) {
                $chunk = $stream->read(8192);

                if ($chunk === '') {
                    break;
                }

                $captured .= $chunk;
            }

            $bodies[] = $captured;

            return new Response($status, [], $body);
        };
    }

    public function testSynthesizeReturnsAudioBytesInsteadOfJsonDecodingThem(): void
    {
        $audio = "\x49\x44\x33\x03" . 'mp3-bytes';
        $history = [];
        $provider = $this->makeProvider([new Response(200, ['Content-Type' => 'audio/mpeg'], $audio)], $history);

        $response = $provider->synthesize(new AudioRequest('hola'));

        $this->assertSame($audio, $response->content);
        $this->assertSame('mp3', $response->format);
        $this->assertSame('https://api.openai.com/v1/audio/speech', (string) $history[0]['request']->getUri());
        $this->assertSame('Bearer sk-test', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testTranscribeSendsMultipartAndReturnsTheText(): void
    {
        $history = [];
        $provider = $this->makeProvider([new Response(200, [], '{"text":"transcript"}')], $history);
        $path = $this->tempFile('AUDIO');

        try {
            $response = $provider->transcribe(new TranscriptionRequest($path, 'es', 'whisper-1'));
        } finally {
            unlink($path);
        }

        $this->assertSame('transcript', $response->text);

        $request = $history[0]['request'];
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions', (string) $request->getUri());
        $this->assertStringStartsWith(
            'multipart/form-data; boundary=',
            $request->getHeaderLine('Content-Type'),
            'the multipart body must override the client default JSON Content-Type, which is what the '
            . 'old array_diff_key(headers, [Content-Type]) workaround was for',
        );

        $body = (string) $request->getBody();
        $this->assertStringContainsString('whisper-1', $body);
        $this->assertStringContainsString('name="language"', $body);
        $this->assertStringContainsString('AUDIO', $body);
    }

    /**
     * Before this change a 400 from an audio endpoint escaped as a raw Guzzle exception, so a caller
     * could not catch it with the package's own type.
     */
    public function testHttpFailureSurfacesAsThePackageException(): void
    {
        $provider = $this->makeProvider([new Response(400, [], '{"error":"bad model"}')]);

        try {
            $provider->synthesize(new AudioRequest('hola'));
            $this->fail('Expected a ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(400, $e->statusCode);
            $this->assertSame('https://api.openai.com/v1', $e->endpoint);
        }
    }

    public function testTheConfiguredRetryPolicyNowReachesTheAudioTransport(): void
    {
        $history = [];
        $provider = $this->makeProvider(
            [new Response(503, [], ''), new Response(200, [], 'AUDIO')],
            $history,
            retries: 1,
        );

        $this->assertSame('AUDIO', $provider->synthesize(new AudioRequest('hola'))->content);
        $this->assertCount(2, $history, 'the configured retry budget must reach the audio transport');
    }

    public function testRetriedTranscriptionUploadsTheWholeFileAgain(): void
    {
        $bodies = [];
        $provider = $this->makeProvider(
            [
                $this->captureBody($bodies, 503),
                $this->captureBody($bodies, 200, '{"text":"ok"}'),
            ],
            retries: 1,
        );
        $path = $this->tempFile('AUDIO-BYTES');

        try {
            $provider->transcribe(new TranscriptionRequest($path));
        } finally {
            unlink($path);
        }

        $this->assertCount(2, $bodies, 'a 503 must be retried once');
        $this->assertStringContainsString('AUDIO-BYTES', $bodies[0]);
        $this->assertStringContainsString(
            'AUDIO-BYTES',
            $bodies[1],
            'the Closure part must be re-opened for the retry, not reused as a consumed stream',
        );
    }
}
