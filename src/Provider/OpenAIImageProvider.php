<?php

declare(strict_types=1);

namespace MacroLLM\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Http\HttpClient;
use MacroLLM\Message\ImageRequest;
use MacroLLM\Message\ImageResponse;
use MacroLLM\Message\ImageSize;

final class OpenAIImageProvider implements ImageProviderInterface
{
    public function __construct(
        private readonly ProviderConfig $config,
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function generate(ImageRequest $request): ImageResponse
    {
        $apiKey = $this->config->apiKey;
        if (!$apiKey) {
            throw new MissingApiKeyException('openai');
        }

        $payload = [
            'model'           => $request->model ?? $this->config->defaultModel,
            'prompt'          => $request->prompt,
            'n'               => $request->n,
            'size'            => $this->mapSize($request->size),
            'response_format' => 'b64_json',
        ];
        if ($request->quality !== null) {
            $payload['quality'] = $request->quality;
        }

        $response = (new HttpClient(
            $this->config->baseUrl ?? 'https://api.openai.com/v1',
            ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
            $this->config->timeout ?? 120,
        ))->post('/images/generations', $payload);

        $images = array_column($response['data'] ?? [], 'b64_json');

        return new ImageResponse($images);
    }

    private function mapSize(ImageSize $size): string
    {
        return match ($size) {
            ImageSize::Portrait  => '1024x1792',
            ImageSize::Landscape => '1792x1024',
            default              => '1024x1024',
        };
    }
}
