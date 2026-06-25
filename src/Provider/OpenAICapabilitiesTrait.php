<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use GuzzleHttp\Client;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\ImageRequest;
use MacroLLM\Message\ImageResponse;
use MacroLLM\Message\ImageSize;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;
use MacroLLM\Message\Usage;
use MacroLLM\Http\HttpClient;

/**
 * Shared capability implementations for OpenAI-compatible providers.
 * Any provider using the OpenAI API format can use these trait OpenAICapabilitiesTrait.
 */
trait OpenAICapabilitiesTrait
{
    // ── Embeddings ──────────────────────────────────────────────────────────

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $payload = [
            'model' => $request->model ?? $this->config->defaultModel,
            'input' => $request->inputs,
        ];
        if ($request->dimensions !== null) {
            $payload['dimensions'] = $request->dimensions;
        }

        $response = (new HttpClient(
            $this->baseUrl(),
            $this->headers(),
            $this->config->timeout ?? 30,
        ))->post('/embeddings', $payload);

        $embeddings = array_map(fn(array $d) => $d['embedding'], $response['data'] ?? []);

        return new EmbeddingResponse($embeddings, new Usage(
            promptTokens:     $response['usage']['prompt_tokens'] ?? 0,
            completionTokens: 0,
            totalTokens:      $response['usage']['total_tokens'] ?? 0,
        ));
    }

    // ── Image Generation ────────────────────────────────────────────────────

    public function generate(ImageRequest $request): ImageResponse
    {
        $payload = [
            'model'           => $request->model ?? $this->config->defaultModel,
            'prompt'          => $request->prompt,
            'n'               => $request->n,
            'size'            => $this->mapImageSize($request->size),
            'response_format' => 'b64_json',
        ];
        if ($request->quality !== null) {
            $payload['quality'] = $request->quality;
        }

        $response = (new HttpClient(
            $this->baseUrl(),
            $this->headers(),
            $this->config->timeout ?? 120,
        ))->post('/images/generations', $payload);

        return new ImageResponse(array_column($response['data'] ?? [], 'b64_json'));
    }

    private function mapImageSize(ImageSize $size): string
    {
        return match ($size) {
            ImageSize::Portrait  => '1024x1792',
            ImageSize::Landscape => '1792x1024',
            default              => '1024x1024',
        };
    }

    // ── Audio TTS + STT ─────────────────────────────────────────────────────

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

        $client = new Client([
            'base_uri' => rtrim($this->baseUrl(), '/') . '/',
            'timeout'  => $this->config->timeout ?? 120,
            'headers'  => $this->headers(),
        ]);

        $response = $client->post('audio/speech', ['json' => $payload]);
        return new AudioResponse((string) $response->getBody(), $format);
    }

    public function transcribe(TranscriptionRequest $request): TranscriptionResponse
    {
        $client = new Client([
            'base_uri' => rtrim($this->baseUrl(), '/') . '/',
            'timeout'  => $this->config->timeout ?? 120,
            'headers'  => array_diff_key($this->headers(), ['Content-Type' => '']),
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
