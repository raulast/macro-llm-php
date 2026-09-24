<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\Exception\StructuredOutputUnsupportedException;
use MacroLLM\Message\ContentPart;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Provider\AnthropicProvider;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for AnthropicProvider: toResponse(), parseStreamEvent(), toPayload(),
 * capability interfaces, and auth header shape.
 */
class AnthropicProviderTest extends TestCase
{
    private function makeProvider(): AnthropicProvider
    {
        return new AnthropicProvider(new ProviderConfig(
            apiKey: 'sk-ant-test',
            defaultModel: 'claude-sonnet-4-5',
        ));
    }

    // ── toResponse: basic text ─────────────────────────────────────────────

    // ── Structured output: a forced tool, since Anthropic has no response_format ──

    public function testToPayloadForcesASingleStructuredOutputTool(): void
    {
        $provider = $this->makeProvider();
        $format = ResponseFormat::jsonSchema('person', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $payload = $provider->toPayload(new InternalRequest(
            messages: [InternalMessage::user('Extract the person')],
            responseFormat: $format,
        ));

        $this->assertSame(['type' => 'tool', 'name' => 'structured_output'], $payload['tool_choice']);
        $this->assertCount(1, $payload['tools']);
        $this->assertSame('structured_output', $payload['tools'][0]['name']);
        $this->assertSame('string', $payload['tools'][0]['input_schema']['properties']['name']['type']);
        $this->assertSame(['name'], $payload['tools'][0]['input_schema']['required']);
    }

    public function testToPayloadKeepsTheCallersToolsAlongsideTheForcedOne(): void
    {
        $provider = $this->makeProvider();
        $format = ResponseFormat::jsonSchema('person', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ]);

        $payload = $provider->toPayload(new InternalRequest(
            messages: [InternalMessage::user('Extract')],
            tools: [new ToolDefinition('lookup', 'Looks something up', ['type' => 'object'], fn () => null)],
            responseFormat: $format,
        ));

        $this->assertCount(2, $payload['tools']);
        $this->assertSame('lookup', $payload['tools'][0]['name']);
        $this->assertSame('structured_output', $payload['tools'][1]['name']);
    }

    /**
     * Anthropic has no schema-less JSON mode, and tool-forcing needs a schema to enforce, so `json()` cannot be
     * honoured. Refusing beats sending a request whose constraint does not exist.
     */
    public function testToPayloadRefusesSchemaLessJsonMode(): void
    {
        $provider = $this->makeProvider();

        try {
            $provider->toPayload(new InternalRequest(
                messages: [InternalMessage::user('Give me JSON')],
                responseFormat: ResponseFormat::json(),
            ));
            $this->fail('Expected a StructuredOutputUnsupportedException.');
        } catch (StructuredOutputUnsupportedException $e) {
            $this->assertSame('anthropic', $e->providerName);
            $this->assertSame('no_such_mode', $e->reason);
        }
    }

    public function testToPayloadRefusesACallerToolNamedLikeTheReservedOne(): void
    {
        $provider = $this->makeProvider();

        try {
            $provider->toPayload(new InternalRequest(
                messages: [InternalMessage::user('Extract')],
                tools: [new ToolDefinition('structured_output', 'Collides', ['type' => 'object'], fn () => null)],
                responseFormat: ResponseFormat::jsonSchema('person', [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string']],
                    'required' => ['name'],
                ]),
            ));
            $this->fail('Expected a StructuredOutputUnsupportedException.');
        } catch (StructuredOutputUnsupportedException $e) {
            $this->assertStringContainsString('structured_output', $e->getMessage());
        }
    }

    /**
     * The forced tool carries the ANSWER. Returning it as a ToolCall would send the Agent loop hunting for a tool
     * the caller never registered, so it becomes the content instead — and the finish reason becomes Stop, because
     * from the caller's point of view nothing was called.
     */
    public function testForcedStructuredToolUseBecomesTheResponseContent(): void
    {
        $provider = $this->makeProvider();

        $response = $provider->toResponse([
            'id' => 'msg_1',
            'content' => [
                ['type' => 'text', 'text' => 'Here it is: '],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'structured_output', 'input' => ['name' => 'Ada']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
        ]);

        $this->assertSame('{"name":"Ada"}', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame([], $response->toolCalls);
    }

    public function testAnOrdinaryToolUseIsStillAToolCall(): void
    {
        $provider = $this->makeProvider();

        $response = $provider->toResponse([
            'id' => 'msg_2',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'lookup', 'input' => ['q' => 'x']],
            ],
            'stop_reason' => 'tool_use',
        ]);

        $this->assertNull($response->content);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('lookup', $response->toolCalls[0]->name);
    }

    public function testToResponseParsesTextContent(): void
    {
        $fixture = $this->fixtureJson('anthropic', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertSame('Hello! I am Claude, an AI assistant.', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertEmpty($response->toolCalls);
    }

    public function testToResponseMapsInputOutputTokensToUsage(): void
    {
        $fixture = $this->fixtureJson('anthropic', 'chat-basic');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        // Anthropic: input_tokens → promptTokens, output_tokens → completionTokens
        $this->assertSame(12, $response->usage->promptTokens);
        $this->assertSame(11, $response->usage->completionTokens);
        $this->assertSame(23, $response->usage->totalTokens); // 12 + 11
    }

    public function testToResponsePassesExtraFields(): void
    {
        // Build a fixture with an extra field not in the known-keys list
        $fixture = $this->fixtureJson('anthropic', 'chat-basic');
        $fixture['custom_field'] = 'extra_value';

        $provider = $this->makeProvider();
        $response = $provider->toResponse($fixture);

        $this->assertArrayHasKey('custom_field', $response->extra);
    }

    // ── toResponse: tool calls ─────────────────────────────────────────────

    public function testToResponseParsesToolUse(): void
    {
        $fixture = $this->fixtureJson('anthropic', 'chat-tool-call');
        $provider = $this->makeProvider();

        $response = $provider->toResponse($fixture);

        $this->assertNull($response->content);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertCount(1, $response->toolCalls);

        $tc = $response->toolCalls[0];
        $this->assertSame('toolu_abc123', $tc->id);
        $this->assertSame('get_weather', $tc->name);
        $this->assertSame(['city' => 'Buenos Aires'], $tc->arguments);
    }

    // ── toResponse: finish reason / stop_reason mapping ───────────────────

    #[DataProvider('stopReasonProvider')]
    public function testStopReasonMapping(string $raw, FinishReason $expected): void
    {
        $fixture = [
            'content' => [['type' => 'text', 'text' => 'hi']],
            'stop_reason' => $raw,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];
        $provider = $this->makeProvider();

        $this->assertSame($expected, $provider->toResponse($fixture)->finishReason);
    }

    public static function stopReasonProvider(): array
    {
        return [
            'end_turn'      => ['end_turn', FinishReason::Stop],
            'tool_use'      => ['tool_use', FinishReason::ToolCalls],
            'max_tokens'    => ['max_tokens', FinishReason::Length],
            'stop_sequence' => ['stop_sequence', FinishReason::Stop],
            'unknown'       => ['other', FinishReason::Stop],
        ];
    }

    // ── parseStreamEvent ──────────────────────────────────────────────────

    public function testParseStreamEventContentBlockDeltaReturnsDelta(): void
    {
        $provider = $this->makeProvider();
        $raw = 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertSame('Hello', $chunk->delta);
        $this->assertFalse($chunk->finished);
    }

    public function testParseStreamEventMessageDeltaReturnsFinished(): void
    {
        $provider = $this->makeProvider();
        $raw = 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":2}}';

        $chunk = $provider->parseStreamEvent($raw, 0);

        $this->assertNotNull($chunk);
        $this->assertTrue($chunk->finished);
    }

    public function testParseStreamEventMessageStopReturnsFinished(): void
    {
        $provider = $this->makeProvider();

        $chunk = $provider->parseStreamEvent('event: message_stop', 0);

        $this->assertNotNull($chunk);
        $this->assertTrue($chunk->finished);
    }

    public function testParseStreamEventNonDataLineReturnsNull(): void
    {
        $provider = $this->makeProvider();

        $this->assertNull($provider->parseStreamEvent('event: message_start', 0));
        $this->assertNull($provider->parseStreamEvent('', 0));
    }

    public function testParseStreamEventUnknownDataTypeReturnsNull(): void
    {
        $provider = $this->makeProvider();
        $raw = 'data: {"type":"ping"}';

        // ping type is not handled — returns null
        $this->assertNull($provider->parseStreamEvent($raw, 0));
    }

    // ── toPayload: tool definitions ────────────────────────────────────────

    public function testToPayloadMapsToolsWithInputSchema(): void
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
        $mapped = $payload['tools'][0];
        // Anthropic uses 'input_schema' not 'parameters'
        $this->assertSame('get_weather', $mapped['name']);
        $this->assertArrayHasKey('input_schema', $mapped);
        $this->assertArrayNotHasKey('parameters', $mapped);
    }

    // ── toPayload: system prompt ───────────────────────────────────────────

    public function testToPayloadExcludesSystemFromMessages(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([
            InternalMessage::system('Be helpful'),
            InternalMessage::user('Hello'),
        ]);

        $payload = $provider->toPayload($request);

        $this->assertArrayHasKey('system', $payload);
        $this->assertSame('Be helpful', $payload['system']);
        // Messages should only contain non-system messages
        $this->assertCount(1, $payload['messages']);
        $this->assertSame('user', $payload['messages'][0]['role']);
    }

    // ── toPayload: this provider has no response_format field, ever ────────

    /**
     * Replaces `testToPayloadIgnoresResponseFormat`, which asserted the absence of a key this provider never
     * emits — so it passed whether or not the feature existed, and it pinned the REMOVED defect as expected
     * behaviour. An independent verification caught it. This version pins something that can actually fail: a
     * caller who asked for nothing must not be silently forced into a tool.
     */
    public function testToPayloadWithoutAResponseFormatForcesNothing(): void
    {
        $provider = $this->makeProvider();

        $payload = $provider->toPayload(new InternalRequest(
            messages: [InternalMessage::user('Just answer')],
        ));

        $this->assertArrayNotHasKey('tool_choice', $payload);
        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('response_format', $payload, 'this provider has no such field, ever');
    }

    /** A caller who brought their own tools must not be silently forced onto one of them. */
    public function testToPayloadWithCallerToolsForcesNothingEither(): void
    {
        $provider = $this->makeProvider();

        $payload = $provider->toPayload(new InternalRequest(
            messages: [InternalMessage::user('Look it up')],
            tools: [new ToolDefinition('lookup', 'Looks something up', ['type' => 'object'], fn () => null)],
        ));

        $this->assertArrayHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
    }

    // ── toPayload: multimodal ──────────────────────────────────────────────

    public function testToPayloadMultimodalBase64UsesAnthropicSourceShape(): void
    {
        $provider = $this->makeProvider();
        $request = new InternalRequest([
            InternalMessage::userWithParts(
                ContentPart::text('What is this?'),
                ContentPart::imageBase64('iVBORw0KGgo=', 'image/png'),
            ),
        ]);

        $payload = $provider->toPayload($request);
        $content = $payload['messages'][0]['content'];

        // Text part
        $this->assertSame('text', $content[0]['type']);
        // Image part: Anthropic uses source.type = 'base64'
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('base64', $content[1]['source']['type']);
        $this->assertSame('image/png', $content[1]['source']['media_type']);
        $this->assertSame('iVBORw0KGgo=', $content[1]['source']['data']);
    }

    public function testToPayloadMultimodalUrlUsesAnthropicUrlShape(): void
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

        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('url', $content[1]['source']['type']);
        $this->assertSame('https://example.com/img.jpg', $content[1]['source']['url']);
    }

    // ── Capability interfaces ─────────────────────────────────────────────

    public function testAnthropicDoesNotImplementSpecialCapabilities(): void
    {
        $p = $this->makeProvider();

        $this->assertNotInstanceOf(EmbeddingProviderInterface::class, $p);
        $this->assertNotInstanceOf(ImageProviderInterface::class, $p);
        $this->assertNotInstanceOf(AudioProviderInterface::class, $p);
        $this->assertNotInstanceOf(RerankingProviderInterface::class, $p);
    }

    // ── Headers ─────────────────────────────────────────────────────────────

    public function testHeadersUseXApiKey(): void
    {
        $provider = $this->makeProvider();
        $headers = $provider->headers();

        $this->assertArrayHasKey('x-api-key', $headers);
        $this->assertSame('sk-ant-test', $headers['x-api-key']);
        $this->assertArrayHasKey('anthropic-version', $headers);
        $this->assertArrayNotHasKey('Authorization', $headers);
    }

    // ── Identity ─────────────────────────────────────────────────────────

    public function testName(): void
    {
        $this->assertSame('anthropic', $this->makeProvider()->name());
    }

    public function testEndpointPath(): void
    {
        $this->assertSame('/messages', $this->makeProvider()->endpointPath());
    }
}
