<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;

final class OpenAIAudioProvider implements AudioProviderInterface
{
    use ProviderHttpClientTrait;

    public function __construct(
        private readonly ProviderConfig $config,
        // Test seam: see ProviderHttpClientTrait. `@internal` — never set by production code.
        private readonly ?\Closure $httpHandlerFactory = null,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function baseUrl(): string
    {
        return $this->config->baseUrl ?? 'https://api.openai.com/v1';
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('openai');
        }

        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    public function synthesize(AudioRequest $request): AudioResponse
    {
        $format = $request->format ?? 'mp3';

        $payload = [
            'model' => $request->model ?? 'tts-1',
            'input' => $request->text,
            'voice' => $request->voice ?? 'alloy',
        ];
        if ($request->instructions !== null) {
            $payload['instructions'] = $request->instructions;
        }

        // TTS answers with audio bytes rather than JSON, so this is the unparsed-response path. It is
        // also why this class no longer needs a Guzzle client of its own.
        $audio = $this->httpClient($this->config->timeout ?? 120)->postRaw('audio/speech', $payload);

        return new AudioResponse($audio, $format);
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        // A Closure part, not a bare fopen(): the stream is opened again for every attempt, so a
        // retried upload carries the whole file instead of an empty part (HC-12).
        $multipart = [
            ['name' => 'model', 'contents' => $request->model ?? 'whisper-1'],
            ['name' => 'file', 'contents' => fn () => fopen($request->filePath, 'r'), 'filename' => basename($request->filePath)],
        ];
        if ($request->language !== null) {
            $multipart[] = ['name' => 'language', 'contents' => $request->language];
        }

        $data = $this->httpClient($this->config->timeout ?? 120)
            ->postMultipart('audio/transcriptions', $multipart);

        return new TranscriptionResponse($data['text'] ?? '');
    }
}
