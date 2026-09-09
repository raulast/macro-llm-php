<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\ProviderConfig;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Provider\AnthropicProvider;
use MacroLLM\Provider\GeminiProvider;
use MacroLLM\Provider\OpenAIProvider;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;

/**
 * Regression tests for BUG 1: empty tool call arguments must serialize as
 * a JSON object {} and not a JSON array [].
 *
 * Root cause: ToolCall::$arguments is a PHP array. An empty PHP array
 * serializes to JSON array [] unless cast to (object) first.
 * All three provider APIs reject [] for the arguments/input/args field.
 *
 * These tests assert on the JSON-encoded form (or the PHP value after encoding)
 * so the array-vs-object distinction is actually caught. Asserting on a raw
 * PHP [] value would pass even with the bug present.
 */
class ToolArgumentsSerializationTest extends TestCase
{
    // ── OpenAI-compatible ─────────────────────────────────────────────────

    public function testOpenAIEmptyArgumentsSerializeAsJsonObject(): void
    {
        $provider = new OpenAIProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'call_1', name: 'list_nodes', arguments: []),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        $toolCalls = $payload['messages'][0]['tool_calls'];
        $this->assertCount(1, $toolCalls);

        $serialized = $toolCalls[0]['function']['arguments'];

        // Must be the string "{}" — not "[]"
        $this->assertSame('{}', $serialized, 'Empty arguments must serialize as JSON object, not array');
    }

    public function testOpenAINonEmptyArgumentsRoundTrip(): void
    {
        $provider = new OpenAIProvider(new ProviderConfig(
            apiKey: 'test-key',
            defaultModel: 'gpt-4o',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'call_1', name: 'get_weather', arguments: ['city' => 'BA']),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        $serialized = $payload['messages'][0]['tool_calls'][0]['function']['arguments'];

        // Non-empty arguments must still round-trip correctly
        $this->assertSame('{"city":"BA"}', $serialized);
        $decoded = json_decode($serialized, true);
        $this->assertSame(['city' => 'BA'], $decoded);
    }

    // ── Anthropic ─────────────────────────────────────────────────────────

    public function testAnthropicEmptyArgumentsSerializeAsJsonObject(): void
    {
        $provider = new AnthropicProvider(new ProviderConfig(
            apiKey: 'sk-ant-test',
            defaultModel: 'claude-sonnet-4-5',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'toolu_1', name: 'audit_flow', arguments: []),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        // Find the tool_use block in the assistant message content
        $content = $payload['messages'][0]['content'];
        $toolUseBlock = null;
        foreach ($content as $block) {
            if ($block['type'] === 'tool_use') {
                $toolUseBlock = $block;
                break;
            }
        }

        $this->assertNotNull($toolUseBlock, 'tool_use block must be present');

        // Anthropic keeps the value as a PHP value for the HTTP client to JSON-encode.
        // The (object) cast makes the empty array an stdClass, which json_encode
        // serializes as {}. Verify via json_encode.
        $encoded = json_encode($toolUseBlock['input']);
        $this->assertSame('{}', $encoded, 'Empty input must encode to JSON object, not array');
    }

    public function testAnthropicNonEmptyArgumentsRoundTrip(): void
    {
        $provider = new AnthropicProvider(new ProviderConfig(
            apiKey: 'sk-ant-test',
            defaultModel: 'claude-sonnet-4-5',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'toolu_1', name: 'get_weather', arguments: ['city' => 'BA']),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        $content = $payload['messages'][0]['content'];
        $toolUseBlock = null;
        foreach ($content as $block) {
            if ($block['type'] === 'tool_use') {
                $toolUseBlock = $block;
                break;
            }
        }

        $this->assertNotNull($toolUseBlock);

        // Non-empty arguments must encode to the correct JSON object
        $encoded = json_encode($toolUseBlock['input']);
        $this->assertSame('{"city":"BA"}', $encoded);
    }

    // ── Gemini ────────────────────────────────────────────────────────────

    public function testGeminiEmptyArgumentsSerializeAsJsonObject(): void
    {
        $provider = new GeminiProvider(new ProviderConfig(
            apiKey: 'AIza-test',
            defaultModel: 'gemini-2.0-flash',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'export_flow_1', name: 'export_flow', arguments: []),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        // Find the functionCall part in the model message
        $parts = $payload['contents'][0]['parts'];
        $functionCallPart = null;
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCallPart = $part['functionCall'];
                break;
            }
        }

        $this->assertNotNull($functionCallPart, 'functionCall part must be present');

        // Gemini keeps the value as a PHP value — verify via json_encode
        $encoded = json_encode($functionCallPart['args']);
        $this->assertSame('{}', $encoded, 'Empty args must encode to JSON object, not array');
    }

    public function testGeminiNonEmptyArgumentsRoundTrip(): void
    {
        $provider = new GeminiProvider(new ProviderConfig(
            apiKey: 'AIza-test',
            defaultModel: 'gemini-2.0-flash',
        ));

        $request = new InternalRequest([
            InternalMessage::assistant(null, [
                new ToolCall(id: 'get_weather_1', name: 'get_weather', arguments: ['city' => 'BA']),
            ]),
        ]);

        $payload = $provider->toPayload($request);

        $parts = $payload['contents'][0]['parts'];
        $functionCallPart = null;
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $functionCallPart = $part['functionCall'];
                break;
            }
        }

        $this->assertNotNull($functionCallPart);

        // Non-empty args must encode correctly
        $encoded = json_encode($functionCallPart['args']);
        $this->assertSame('{"city":"BA"}', $encoded);
    }
}
