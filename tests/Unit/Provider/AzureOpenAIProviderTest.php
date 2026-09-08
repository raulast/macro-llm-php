<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Message\FinishReason;
use MacroLLM\Provider\AzureOpenAIProvider;
use MacroLLM\Tests\TestCase;

class AzureOpenAIProviderTest extends TestCase
{
    private function makeProvider(): AzureOpenAIProvider
    {
        return new AzureOpenAIProvider(new ProviderConfig(
            apiKey: 'azure-api-key',
            defaultModel: 'gpt-4o',
            extraHeaders: [
                'resource'    => 'my-resource',
                'deployment'  => 'gpt4o-dep',
                'api_version' => '2024-02-01',
            ],
        ));
    }

    public function testToResponseParsesFixture(): void
    {
        $fixture = $this->fixtureJson('azure', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello from Azure OpenAI!', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(13, $response->usage->totalTokens);
    }

    public function testName(): void
    {
        $this->assertSame('azure', $this->makeProvider()->name());
    }

    public function testBaseUrlContainsAzureAndResource(): void
    {
        $url = $this->makeProvider()->baseUrl();

        $this->assertStringContainsString('openai.azure.com', $url);
        $this->assertStringContainsString('my-resource', $url);
    }

    public function testEndpointPathContainsApiVersion(): void
    {
        $path = $this->makeProvider()->endpointPath();

        $this->assertStringContainsString('api-version=2024-02-01', $path);
    }

    public function testHeadersUseApiKeyNotBearer(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('api-key', $headers);
        $this->assertSame('azure-api-key', $headers['api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testGetModelsReturnsEmpty(): void
    {
        // Azure does not have a standard models endpoint
        $this->assertSame([], $this->makeProvider()->getModels());
    }

    public function testImplementsEmbeddingAndImage(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }
}
