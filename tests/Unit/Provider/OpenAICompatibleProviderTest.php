<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Message\ContentPart;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Provider\AzureOpenAIProvider;
use MacroLLM\Provider\DeepSeekProvider;
use MacroLLM\Provider\GroqProvider;
use MacroLLM\Provider\LlamaCppProvider;
use MacroLLM\Provider\MistralProvider;
use MacroLLM\Provider\OllamaProvider;
use MacroLLM\Provider\OpenAICompatibleProvider;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Provider\OpenCodeZenGoProvider;
use MacroLLM\Provider\OpenRouterProvider;
use MacroLLM\Provider\XAIProvider;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for OpenAICompatibleProvider normalization (shared by 10 providers).
 *
 * Strategy: test shared normalization thoroughly via the base class,
 * then use data providers to assert per-provider identity differences
 * (name, defaultBaseUrl, endpointPath, headers auth shape, getModels strategy).
 */
class OpenAICompatibleProviderTest extends TestCase
{
    private function makeProvider(string $apiKey = 'test-key', string $model = 'gpt-4o'): OpenAICompatibleProvider
    {
        // Use a concrete subclass (OpenAIProvider) to exercise the shared normalizer
        return new OpenAIProvider(new ProviderConfig(
            apiKey: $apiKey,
            defaultModel: $model,
        ));
    }

    // ── toResponse: basic chat ─────────────────────────────────────────────

    public function testToResponseParsesContentAndUsage(): void
    {
        $fixture = $this->fixtureJson('openai', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello! How can I help you today?', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame(10, $response->usage->promptTokens);
        $this->assertSame(9, $response->usage->completionTokens);
        $this->assertSame(19, $response->usage->totalTokens);
        $this->assertEmpty($response->toolCalls);
    }

    public function testToResponsePassesExtraFields(): void
    {
        $fixture = $this->fixtureJson('openai', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // 'service_tier' is not in the known-keys list → should appear in extra
        $this->assertArrayHasKey('service_tier', $response->extra);
        $this->assertSame('default', $response->extra['service_tier']);
    }

    public function testToResponseDoesNotPassKnownKeysToExtra(): void
    {
        $fixture = $this->fixtureJson('openai', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // Known keys should NOT appear in extra
        foreach (['id', 'object', 'created', 'model', 'choices', 'usage', 'system_fingerprint'] as $key) {
            $this->assertArrayNotHasKey($key, $response->extra, "Known key '{$key}' leaked into extra");
        }
    }

    // ── toResponse: tool calls ──────────────────────────────────────────────

    public function testToResponseParsesToolCalls(): void
    {
        $fixture = $this->fixtureJson('openai', 'chat-tool-call');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertNull($response->content);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertCount(1, $response->toolCalls);

        $tc = $response->toolCalls[0];
        $this->assertSame('call_abc123', $tc->id);
        $this->assertSame('get_weather', $tc->name);
        $this->assertSame(['city' => 'Buenos Aires'], $tc->arguments);
    }

    // ── toResponse: finish reason mapping ─────────────────────────────────

    #[DataProvider('finishReasonProvider')]
    public function testFinishReasonMapping(string $raw, FinishReason $expected): void
    {
        $fixture = [
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => $raw]],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame($expected, $response->finishReason);
    }

    public static function finishReasonProvider(): array
    {
        return [
            'stop'           => ['stop', FinishReason::Stop],
            'tool_calls'     => ['tool_calls', FinishReason::ToolCalls],
            'length'         => ['length', FinishReason::Length],
            'content_filter' => ['content_filter', FinishReason::ContentFilter],
            'unknown'        => ['some_unknown', FinishReason::Stop],
        ];
    }

    // ── toResponse: missing usage ──────────────────────────────────────────

    public function testToResponseHandlesMissingUsage(): void
    {
        $fixture = [
            'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
        ];
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame(0, $response->usage->totalTokens);
    }

    // ── parseStreamEvent ──────────────────────────────────────────────────

    public function testParseStreamEventDeltaContent(): void
    {
        $provider = $this->makeProvider();
        $raw = 'data: {"id":"c1","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertSame('Hello', $chunk->delta);
        $this->assertFalse($chunk->finished);
    }

    public function testParseStreamEventDoneReturnsFinished(): void
    {
        $provider = $this->makeProvider();

        $chunk = $provider->parseStreamEvent('data: [DONE]', 0);

        $this->assertNotNull($chunk);
        $this->assertTrue($chunk->finished);
    }

    public function testParseStreamEventEmptyLineReturnsNull(): void
    {
        $provider = $this->makeProvider();

        $result = $provider->parseStreamEvent('', 0);

        $this->assertNull($result);
    }

    public function testParseStreamEventNonDataLineReturnsNull(): void
    {
        $provider = $this->makeProvider();

        $result = $provider->parseStreamEvent('event: start', 0);

        $this->assertNull($result);
    }

    public function testParseStreamEventWithFinishReasonReturnsFinished(): void
    {
        $provider = $this->makeProvider();
        $raw = 'data: {"id":"c1","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertTrue($chunk->finished);
    }

    // ── toPayload: multimodal ──────────────────────────────────────────────

    public function testToPayloadMultimodalBase64Image(): void
    {
        $provider = $this->makeProvider();

        $request = new InternalRequest([
            InternalMessage::userWithParts(
                ContentPart::text('What is in this image?'),
                ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
            ),
        ]);

        $payload = $provider->toPayload($request);

        $messages = $payload['messages'];
        $this->assertCount(1, $messages);
        $content = $messages[0]['content'];
        $this->assertIsArray($content);

        // First part: text
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('What is in this image?', $content[0]['text']);

        // Second part: image_url with base64 data URI
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']['url']);
    }

    public function testToPayloadMultimodalUrlImage(): void
    {
        $provider = $this->makeProvider();

        $request = new InternalRequest([
            InternalMessage::userWithParts(
                ContentPart::text('Describe this'),
                ContentPart::imageUrl('https://example.com/img.jpg'),
            ),
        ]);

        $payload = $provider->toPayload($request);
        $content = $payload['messages'][0]['content'];

        $this->assertSame('image_url', $content[1]['type']);
        $this->assertSame('https://example.com/img.jpg', $content[1]['image_url']['url']);
    }

    // ── toPayload: ResponseFormat ──────────────────────────────────────────

    public function testToPayloadJsonSchemaResponseFormat(): void
    {
        $provider = $this->makeProvider();
        $format = ResponseFormat::jsonSchema('person', [
            'type'       => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required'   => ['name'],
        ]);

        $request = new InternalRequest(
            messages: [InternalMessage::user('Extract person')],
            responseFormat: $format,
        );

        $payload = $provider->toPayload($request);

        $this->assertArrayHasKey('response_format', $payload);
        $this->assertSame('json_schema', $payload['response_format']['type']);
        $this->assertSame('person', $payload['response_format']['json_schema']['name']);
        $this->assertArrayHasKey('schema', $payload['response_format']['json_schema']);
    }

    // ── toPayload: tool definitions ────────────────────────────────────────

    public function testToPayloadMapsToolDefinitions(): void
    {
        $provider = $this->makeProvider();
        $tool = new ToolDefinition(
            name: 'get_weather',
            description: 'Get weather for a city',
            parameters: [
                'type'       => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required'   => ['city'],
            ],
            callable: fn($a) => 'sunny',
        );

        $request = new InternalRequest(
            messages: [InternalMessage::user('Weather?')],
            tools: [$tool],
        );

        $payload = $provider->toPayload($request);

        $this->assertArrayHasKey('tools', $payload);
        $this->assertCount(1, $payload['tools']);
        $mapped = $payload['tools'][0];
        $this->assertSame('function', $mapped['type']);
        $this->assertSame('get_weather', $mapped['function']['name']);
        $this->assertSame('Get weather for a city', $mapped['function']['description']);
        $this->assertArrayHasKey('parameters', $mapped['function']);
    }

    // ── Per-provider identity differences ─────────────────────────────────

    #[DataProvider('providerIdentityProvider')]
    public function testProviderName(string $class, string $expectedName): void
    {
        $provider = new $class(new ProviderConfig(apiKey: 'test', defaultModel: 'some-model'));
        $this->assertSame($expectedName, $provider->name());
    }

    #[DataProvider('providerIdentityProvider')]
    public function testProviderEndpointPath(string $class, string $name, string $expectedEndpoint): void
    {
        $provider = new $class(new ProviderConfig(apiKey: 'test', defaultModel: 'some-model'));
        $this->assertSame($expectedEndpoint, $provider->endpointPath());
    }

    public static function providerIdentityProvider(): array
    {
        return [
            'openai'           => [OpenAIProvider::class, 'openai', '/chat/completions'],
            'groq'             => [GroqProvider::class, 'groq', '/chat/completions'],
            'openrouter'       => [OpenRouterProvider::class, 'openrouter', '/chat/completions'],
            'ollama'           => [OllamaProvider::class, 'ollama', '/chat/completions'],
            'llamacpp'         => [LlamaCppProvider::class, 'llamacpp', '/chat/completions'],
            'mistral'          => [MistralProvider::class, 'mistral', '/chat/completions'],
            'deepseek'         => [DeepSeekProvider::class, 'deepseek', '/chat/completions'],
            'xai'              => [XAIProvider::class, 'xai', '/chat/completions'],
            'opencode-zen-go'  => [OpenCodeZenGoProvider::class, 'opencode-zen-go', '/zen/go/v1/chat/completions'],
        ];
    }

    // ── Auth header shape per provider ─────────────────────────────────────

    public function testOpenAIHeadersUseBearer(): void
    {
        $provider = new OpenAIProvider(new ProviderConfig(apiKey: 'sk-test', defaultModel: 'gpt-4o'));
        $headers = $provider->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
    }

    public function testOllamaHeadersOmitAuthWhenNoKey(): void
    {
        $provider = new OllamaProvider(new ProviderConfig(apiKey: null, defaultModel: 'llama3'));
        $headers = $provider->headers();

        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testOllamaHeadersIncludeAuthWhenKeySet(): void
    {
        $provider = new OllamaProvider(new ProviderConfig(apiKey: 'localkey', defaultModel: 'llama3'));
        $headers = $provider->headers();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Bearer ', $headers['Authorization']);
    }

    public function testLlamaCppHeadersHaveNoAuth(): void
    {
        $provider = new LlamaCppProvider(new ProviderConfig(apiKey: 'any', defaultModel: 'llama'));
        $headers = $provider->headers();

        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    public function testAzureHeadersUseApiKey(): void
    {
        $provider = new AzureOpenAIProvider(new ProviderConfig(
            apiKey: 'azure-secret',
            defaultModel: 'gpt-4o',
            extraHeaders: ['resource' => 'myresource', 'deployment' => 'gpt4o', 'api_version' => '2024-02-01'],
        ));
        $headers = $provider->headers();

        $this->assertArrayHasKey('api-key', $headers);
        $this->assertSame('azure-secret', $headers['api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // ── getModels strategy ─────────────────────────────────────────────────

    public function testDeepSeekGetModelsReturnsStaticList(): void
    {
        $provider = new DeepSeekProvider(new ProviderConfig(apiKey: 'test', defaultModel: 'deepseek-chat'));
        $models = $provider->getModels();

        $this->assertNotEmpty($models);
        $this->assertContains('deepseek-chat', $models);
    }

    public function testOpenAIGetModelsReturnsStaticList(): void
    {
        $provider = new OpenAIProvider(new ProviderConfig(apiKey: 'test', defaultModel: 'gpt-4o'));
        $models = $provider->getModels();

        $this->assertNotEmpty($models);
        $this->assertContains('gpt-4o', $models);
    }

    public function testAzureGetModelsReturnsEmpty(): void
    {
        $provider = new AzureOpenAIProvider(new ProviderConfig(
            apiKey: 'test',
            defaultModel: 'gpt-4o',
            extraHeaders: ['resource' => 'r', 'deployment' => 'd', 'api_version' => '2024-02-01'],
        ));

        // Azure does not have a standard models endpoint — returns []
        $this->assertSame([], $provider->getModels());
    }

    // ── Capability interface assertions (instanceof) ───────────────────────

    public function testOpenAIImplementsAllCapabilities(): void
    {
        $p = new OpenAIProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(ProviderInterface::class, $p);
        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(RerankingProviderInterface::class, $p);
    }

    public function testGroqImplementsEmbeddingAndAudio(): void
    {
        $p = new GroqProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
    }

    public function testOpenRouterImplementsEmbedding(): void
    {
        $p = new OpenRouterProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testOllamaImplementsEmbeddingOnly(): void
    {
        $p = new OllamaProvider(new ProviderConfig(apiKey: null, defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testLlamaCppImplementsEmbeddingOnly(): void
    {
        $p = new LlamaCppProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testMistralImplementsEmbeddingAndAudio(): void
    {
        $p = new MistralProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
    }

    public function testXaiImplementsEmbeddingAndImage(): void
    {
        $p = new XAIProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testAzureImplementsEmbeddingAndImage(): void
    {
        $p = new AzureOpenAIProvider(new ProviderConfig(
            apiKey: 'k',
            defaultModel: 'm',
            extraHeaders: ['resource' => 'r', 'deployment' => 'd', 'api_version' => '2024-02-01'],
        ));

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testDeepSeekHasNoCapsInterfaces(): void
    {
        $p = new DeepSeekProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    public function testOpenCodeZenGoHasNoCapsInterfaces(): void
    {
        $p = new OpenCodeZenGoProvider(new ProviderConfig(apiKey: 'k', defaultModel: 'm'));

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
    }

    // ── toPayload: no response_format when null ────────────────────────────

    public function testToPayloadOmitsResponseFormatWhenNull(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([InternalMessage::user('Hello')]);

        $payload = $provider->toPayload($request);

        $this->assertArrayNotHasKey('response_format', $payload);
    }

    // ── toPayload: stream flag ─────────────────────────────────────────────

    public function testToPayloadIncludesStreamFlagWhenSet(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest(
            messages: [InternalMessage::user('Hi')],
            stream: true,
        );

        $payload = $provider->toPayload($request);

        $this->assertTrue($payload['stream']);
    }
}
