<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use GuzzleHttp\Client;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;
use MacroLLM\Message\Usage;

/**
 * ElevenLabs provider — TTS only (AudioProviderInterface).
 * Free tier: 10,000 chars/month.
 *
 * Default voice: Rachel (21m00Tcm4TlvDq8ikWAM) — available on all plans.
 * Find other voice IDs at https://elevenlabs.io/app/voice-library
 */
final class ElevenLabsProvider implements ProviderInterface, AudioProviderInterface
{
    // Rachel — default free-tier voice
    private const DEFAULT_VOICE_ID = '21m00Tcm4TlvDq8ikWAM';
    private const DEFAULT_MODEL    = 'eleven_multilingual_v2';

    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'elevenlabs';
    }

    public function baseUrl(): string
    {
        return $this->config->baseUrl ?? 'https://api.elevenlabs.io';
    }

    public function headers(): array
    {
        $key = $this->config->apiKey;
        if (!$key) {
            throw new MissingApiKeyException('elevenlabs');
        }
        return [
            'xi-api-key'   => $key,
            'Content-Type' => 'application/json',
        ];
    }

    // ── AudioProviderInterface ───────────────────────────────────────────────

    public function synthesize(AudioRequest $request): AudioResponse
    {
        $key = $this->config->apiKey;
        if (!$key) {
            throw new MissingApiKeyException('elevenlabs');
        }

        // voice comes from request->voice (voice_id) or config default_model (reused as voice_id)
        $voiceId = $request->voice ?? $this->config->defaultModel ?? self::DEFAULT_VOICE_ID;
        $model   = $request->model ?? self::DEFAULT_MODEL;
        $format  = $request->format ?? 'mp3';

        $payload = [
            'text'     => $request->text,
            'model_id' => $model,
        ];

        $client = new Client([
            'base_uri' => rtrim($this->baseUrl(), '/') . '/',
            'timeout'  => $this->config->timeout ?? 60,
            'headers'  => $this->headers(),
        ]);

        $response = $client->post("v1/text-to-speech/{$voiceId}", ['json' => $payload]);

        return new AudioResponse((string) $response->getBody(), $format);
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        // ElevenLabs has a STT endpoint but it's not in the free tier
        // Transcription requests should use a different provider (e.g. groq/whisper)
        throw new \RuntimeException("ElevenLabs does not support transcription on the free tier. Use 'groq' or 'openai' for STT.");
    }

    // ── ProviderInterface stubs (ElevenLabs is TTS-only) ────────────────────

    public function endpointPath(): string { return '/v1/text-to-speech/' . self::DEFAULT_VOICE_ID; }

    public function toPayload(InternalRequest $request): array { return []; }

    public function toResponse(array $providerResponse): InternalResponse
    {
        return new InternalResponse(content: null, finishReason: FinishReason::Stop, usage: new Usage());
    }

    public function parseStreamEvent(string $rawEvent, int $index): ?StreamChunk { return null; }

    public function supportsStreaming(): bool { return false; }

    public function getModels(): array
    {
        return [
            'eleven_multilingual_v2',
            'eleven_turbo_v2_5',
            'eleven_turbo_v2',
            'eleven_monolingual_v1',
        ];
    }
}
