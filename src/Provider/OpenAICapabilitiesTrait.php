<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

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

        $response = $this->httpClient($this->config->timeout ?? 30)->post('/embeddings', $payload);

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

        $response = $this->httpClient($this->config->timeout ?? 120)->post('/images/generations', $payload);

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

        // TTS answers with audio bytes rather than JSON, so this uses the unparsed-response path.
        $audio = $this->httpClient($this->config->timeout ?? 120)
            ->postRaw('audio/speech', $payload);

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
