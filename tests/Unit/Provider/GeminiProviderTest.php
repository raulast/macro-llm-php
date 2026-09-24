<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Exception\SchemaException;
use MacroLLM\Message\ContentPart;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Provider\GeminiProvider;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for GeminiProvider: toResponse(), parseStreamEvent(), toPayload(),
 * capability interfaces.
 */
class GeminiProviderTest extends TestCase
{
    private function makeProvider(): GeminiProvider
    {
        return new GeminiProvider(new ProviderConfig(
            apiKey: 'AIza-test',
            defaultModel: 'gemini-2.0-flash',
        ));
    }

    // ── toResponse: basic text ─────────────────────────────────────────────

    public function testToResponseParsesTextContent(): void
    {
        $fixture = $this->fixtureJson('gemini', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello! How can I assist you today?', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertEmpty($response->toolCalls);
    }

    public function testToResponseMapsUsageMetadata(): void
    {
        $fixture = $this->fixtureJson('gemini', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // Gemini: promptTokenCount → promptTokens, candidatesTokenCount → completionTokens
        $this->assertSame(8, $response->usage->promptTokens);
        $this->assertSame(9, $response->usage->completionTokens);
        $this->assertSame(17, $response->usage->totalTokens);
    }

    public function testToResponsePassesExtraFields(): void
    {
        $fixture = $this->fixtureJson('gemini', 'chat-basic');
        $fixture['extra_field'] = 'extra_value';
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // extra_field not in known keys → appears in extra
        $this->assertArrayHasKey('extra_field', $response->extra);
    }

    // ── toResponse: tool calls (functionCall) ─────────────────────────────

    public function testToResponseParsesFunctionCall(): void
    {
        $fixture = $this->fixtureJson('gemini', 'chat-tool-call');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertNull($response->content);
        $this->assertCount(1, $response->toolCalls);

        $tc = $response->toolCalls[0];
        $this->assertSame('get_weather', $tc->name);
        $this->assertSame(['city' => 'Buenos Aires'], $tc->arguments);
        // ID is auto-generated: name + '_' + uniqid — just check it starts with name
        $this->assertStringStartsWith('get_weather_', $tc->id);
    }

    // ── toResponse: finishReason mapping ──────────────────────────────────

    #[DataProvider('finishReasonProvider')]
    public function testFinishReasonMapping(string $raw, FinishReason $expected): void
    {
        $fixture = [
            'candidates' => [['content' => ['parts' => [['text' => 'hi']]], 'finishReason' => $raw]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
        ];

        $this->assertSame($expected, $this->makeProvider()->toResponse($fixture)->finishReason);
    }

    public static function finishReasonProvider(): array
    {
        return [
            'STOP'       => ['STOP', FinishReason::Stop],
            'MAX_TOKENS' => ['MAX_TOKENS', FinishReason::Length],
            'SAFETY'     => ['SAFETY', FinishReason::ContentFilter],
            'RECITATION' => ['RECITATION', FinishReason::ContentFilter],
            'unknown'    => ['OTHER', FinishReason::Stop],
        ];
    }

    // ── parseStreamEvent ──────────────────────────────────────────────────

    public function testParseStreamEventTextDelta(): void
    {
        $provider = $this->makeProvider();
        $raw = '{"candidates":[{"content":{"parts":[{"text":"Hello"}],"role":"model"},"finishReason":"STOP"}],"usageMetadata":{"totalTokenCount":5}}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertSame('Hello', $chunk->delta);
        $this->assertTrue($chunk->finished); // STOP → finished
    }

    public function testParseStreamEventEmptyLinesReturnNull(): void
    {
        $provider = $this->makeProvider();

        $this->assertNull($provider->parseStreamEvent('', 0));
        $this->assertNull($provider->parseStreamEvent('[', 0));
        $this->assertNull($provider->parseStreamEvent(']', 0));
        $this->assertNull($provider->parseStreamEvent(',', 0));
    }

    public function testParseStreamEventInvalidJsonReturnsNull(): void
    {
        $provider = $this->makeProvider();

        $this->assertNull($provider->parseStreamEvent('not json', 0));
    }

    // ── toPayload: tool definitions ────────────────────────────────────────

    public function testToPayloadMapsToolsAsFunctionDeclarations(): void
    {
        $provider = $this->makeProvider();
        $tool = new ToolDefinition(
            name: 'get_weather',
            description: 'Get weather',
            parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            callable: fn($a) => 'sunny',
        );

        $request = new InternalRequest(
            messages: [InternalMessage::user('Weather?')],
            tools: [$tool],
        );

        $payload = $provider->toPayload($request);

        $this->assertArrayHasKey('tools', $payload);
        // Gemini wraps tools as functionDeclarations
        $this->assertArrayHasKey('functionDeclarations', $payload['tools'][0]);
        $fn = $payload['tools'][0]['functionDeclarations'][0];
        $this->assertSame('get_weather', $fn['name']);
    }

    // ── toPayload: structured output emission ──────────────────────────────

    public function testToPayloadEmitsAJsonSchemaThroughTheJsonSchemaChannel(): void
    {
        $provider = $this->makeProvider();
        $format = ResponseFormat::jsonSchema('person', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);
        $request = new InternalRequest(
            messages: [InternalMessage::user('Extract person')],
            responseFormat: $format,
        );

        $config = $provider->toPayload($request)['generationConfig'];

        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertSame('object', $config['responseJsonSchema']['type']);
        $this->assertSame(['type' => 'string'], $config['responseJsonSchema']['properties']['name']);
    }

    /** `json()` is the schema-less JSON mode: a MIME type, and no schema to enforce. */
    public function testToPayloadEmitsJsonModeWithoutASchema(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest(
            messages: [InternalMessage::user('Give me JSON')],
            responseFormat: ResponseFormat::json(),
        );

        $config = $provider->toPayload($request)['generationConfig'];

        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertArrayNotHasKey('responseJsonSchema', $config);
    }

    /**
     * The behaviour this replaces was `testToPayloadIgnoresResponseFormat`, which pinned the silent drop as
     * expected. Ignoring was the defect: the package promises the same request shape on every provider.
     */
    public function testToPayloadRefusesASchemaKeywordTheDialectDoesNotSupport(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest(
            messages: [InternalMessage::user('Extract')],
            responseFormat: ResponseFormat::jsonSchema('x', [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string', 'minLength' => 3]],
            ]),
        );

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/minLength/');

        $provider->toPayload($request);
    }

    public function testToPayloadWithoutAResponseFormatCarriesNoGenerationConfig(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest(messages: [InternalMessage::user('Hi')]);

        $this->assertArrayNotHasKey('generationConfig', $provider->toPayload($request));
    }

    // ── toPayload: multimodal ──────────────────────────────────────────────

    public function testToPayloadMultimodalBase64UsesInlineData(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([
            InternalMessage::userWithParts(
                ContentPart::text('Describe this'),
                ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
            ),
        ]);

        $payload = $provider->toPayload($request);
        $parts = $payload['contents'][0]['parts'];

        $this->assertSame('Describe this', $parts[0]['text']);
        // Gemini uses inlineData for base64
        $this->assertArrayHasKey('inlineData', $parts[1]);
        $this->assertSame('image/png', $parts[1]['inlineData']['mimeType']);
        $this->assertSame('iVBORw0KGgo=', $parts[1]['inlineData']['data']);
    }

    public function testToPayloadMultimodalUrlUsesFileData(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([
            InternalMessage::userWithParts(
                ContentPart::text('What is this?'),
                ContentPart::imageUrl('https://example.com/img.jpg'),
            ),
        ]);

        $payload = $provider->toPayload($request);
        $parts = $payload['contents'][0]['parts'];

        // Gemini uses fileData for image URLs
        $this->assertArrayHasKey('fileData', $parts[1]);
        $this->assertSame('https://example.com/img.jpg', $parts[1]['fileData']['fileUri']);
    }

    // ── toPayload: system instruction ────────────────────────────────────

    public function testToPayloadExtractsSystemAsInstruction(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([
            InternalMessage::system('Be helpful and concise'),
            InternalMessage::user('Hello'),
        ]);

        $payload = $provider->toPayload($request);

        $this->assertArrayHasKey('systemInstruction', $payload);
        // User message only in contents
        $this->assertCount(1, $payload['contents']);
        $this->assertSame('user', $payload['contents'][0]['role']);
    }

    // ── Capability interfaces ─────────────────────────────────────────────

    public function testGeminiImplementsEmbeddingAndImage(): void
    {
        $p = $this->makeProvider();

        $this->assertInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(RerankingProviderInterface::class, $p);
    }

    // ── Identity ──────────────────────────────────────────────────────────

    public function testName(): void
    {
        $this->assertSame('gemini', $this->makeProvider()->name());
    }

    public function testHeaders(): void
    {
        $headers = $this->makeProvider()->headers();

        $this->assertArrayHasKey('x-goog-api-key', $headers);
        $this->assertSame('AIza-test', $headers['x-goog-api-key']);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }
}
