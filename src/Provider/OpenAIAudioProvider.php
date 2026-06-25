<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;
use GuzzleHttp\Client;

final class OpenAIAudioProvider implements AudioProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function synthesize(AudioRequest $request): AudioResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('openai');
        }

        $format = $request->format ?? 'mp3';

        $payload = [
            'model' => $request->model ?? 'tts-1',
            'input' => $request->text,
            'voice' => $request->voice ?? 'alloy',
        ];
        if ($request->instructions !== null) {
            $payload['instructions'] = $request->instructions;
        }

        // Audio response is binary — use Guzzle directly
        $client = new Client([
            'base_uri' => rtrim($this->config->baseUrl ?? 'https://api.openai.com/v1', '/') . '/',
            'timeout'  => $this->config->timeout ?? 120,
            'headers'  => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
        ]);

        $response = $client->post('audio/speech', ['json' => $payload]);

        return new AudioResponse((string) $response->getBody(), $format);
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('openai');
        }

        $client = new Client([
            'base_uri' => rtrim($this->config->baseUrl ?? 'https://api.openai.com/v1', '/') . '/',
            'timeout'  => $this->config->timeout ?? 120,
            'headers'  => ['Authorization' => 'Bearer ' . $apiKey],
        ]);

        $multipart = [
            ['name' => 'model', 'contents' => $request->model ?? 'whisper-1'],
            ['name' => 'file', 'contents' => fopen($request->filePath, 'r'), 'filename' => basename($request->filePath)],
        ];
        if ($request->language !== null) {
            $multipart[] = ['name' => 'language', 'contents' => $request->language];
        }

        $response = $client->post('audio/transcriptions', ['multipart' => $multipart]);
        $data = json_decode((string) $response->getBody(), true);

        return new TranscriptionResponse($data['text'] ?? '');
    }
}
