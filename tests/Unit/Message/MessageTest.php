<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Message;

use PHPUnit\Framework\Attributes\DataProvider;

use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\AudioResponse;
use MacroLLM\Message\ContentPart;
use MacroLLM\Message\ContentPartType;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\ImageRequest;
use MacroLLM\Message\ImageResponse;
use MacroLLM\Message\ImageSize;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\RankedDocument;
use MacroLLM\Message\RerankingRequest;
use MacroLLM\Message\RerankingResponse;
use MacroLLM\Message\ResponseFormat;
use MacroLLM\Message\Role;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;
use MacroLLM\Message\Usage;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;
use MacroLLM\Tool\ToolDefinition;
use MacroLLM\Tool\ToolResult;

final class MessageTest extends TestCase
{
    /** @var string[] Temp files to clean up */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Role enum
    // ────────────────────────────────────────────────────────────────────────

    public function test_role_enum_values(): void
    {
        $this->assertSame('system', Role::System->value);
        $this->assertSame('user', Role::User->value);
        $this->assertSame('assistant', Role::Assistant->value);
        $this->assertSame('tool', Role::Tool->value);
    }

    // ────────────────────────────────────────────────────────────────────────
    // FinishReason enum
    // ────────────────────────────────────────────────────────────────────────

    public function test_finish_reason_enum_values(): void
    {
        $this->assertSame('stop', FinishReason::Stop->value);
        $this->assertSame('tool_calls', FinishReason::ToolCalls->value);
        $this->assertSame('length', FinishReason::Length->value);
        $this->assertSame('content_filter', FinishReason::ContentFilter->value);
        $this->assertSame('error', FinishReason::Error->value);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Usage — field names are promptTokens/completionTokens/totalTokens.
    // There is deliberately NO inputTokens/outputTokens.
    // ────────────────────────────────────────────────────────────────────────

    public function test_usage_field_names_are_prompt_completion_total(): void
    {
        $usage = new Usage(promptTokens: 100, completionTokens: 50, totalTokens: 150);

        $this->assertSame(100, $usage->promptTokens);
        $this->assertSame(50, $usage->completionTokens);
        $this->assertSame(150, $usage->totalTokens);
    }

    public function test_usage_has_no_input_tokens_property(): void
    {
        // Pin: there is no inputTokens or outputTokens — those names were
        // intentionally excluded. Tests that attempt to access them must fail.
        $usage = new Usage(10, 5, 15);

        $this->assertFalse(property_exists($usage, 'inputTokens'));
        $this->assertFalse(property_exists($usage, 'outputTokens'));
    }

    public function test_usage_defaults_to_zero(): void
    {
        $usage = new Usage();

        $this->assertSame(0, $usage->promptTokens);
        $this->assertSame(0, $usage->completionTokens);
        $this->assertSame(0, $usage->totalTokens);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ContentPartType enum
    // ────────────────────────────────────────────────────────────────────────

    public function test_content_part_type_enum_values(): void
    {
        $this->assertSame('text', ContentPartType::Text->value);
        $this->assertSame('image_url', ContentPartType::ImageUrl->value);
        $this->assertSame('image_base64', ContentPartType::ImageBase64->value);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ContentPart factories
    // ────────────────────────────────────────────────────────────────────────

    public function test_content_part_text_factory(): void
    {
        $part = ContentPart::text('Hello world');

        $this->assertSame(ContentPartType::Text, $part->type);
        $this->assertSame('Hello world', $part->value);
        $this->assertNull($part->mimeType);
        // detail defaults to 'auto'
        $this->assertSame('auto', $part->detail);
    }

    public function test_content_part_image_url_factory_default_detail(): void
    {
        $part = ContentPart::imageUrl('https://example.com/img.jpg');

        $this->assertSame(ContentPartType::ImageUrl, $part->type);
        $this->assertSame('https://example.com/img.jpg', $part->value);
        $this->assertSame('auto', $part->detail);
    }

    public function test_content_part_image_url_factory_explicit_detail(): void
    {
        $part = ContentPart::imageUrl('https://example.com/img.jpg', 'high');

        $this->assertSame('high', $part->detail);
    }

    public function test_content_part_image_base64_factory(): void
    {
        $b64 = base64_encode('fake-binary');
        $part = ContentPart::imageBase64($b64, 'image/png');

        $this->assertSame(ContentPartType::ImageBase64, $part->type);
        $this->assertSame($b64, $part->value);
        $this->assertSame('image/png', $part->mimeType);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ResponseFormat
    // ────────────────────────────────────────────────────────────────────────

    public function test_response_format_json_type(): void
    {
        $fmt = ResponseFormat::json();

        $this->assertSame('json_object', $fmt->type);
        $this->assertNull($fmt->name);
        $this->assertNull($fmt->schema);
        // strict defaults to true even for json_object (checked via jsonSchema below)
    }

    public function test_response_format_json_schema_defaults_strict_to_true(): void
    {
        $schema = ['type' => 'object', 'properties' => []];
        $fmt = ResponseFormat::jsonSchema('my_schema', $schema);

        $this->assertSame('json_schema', $fmt->type);
        $this->assertSame('my_schema', $fmt->name);
        $this->assertSame($schema, $fmt->schema);
        $this->assertTrue($fmt->strict);
    }

    public function test_response_format_json_schema_can_set_strict_false(): void
    {
        $fmt = ResponseFormat::jsonSchema('my_schema', [], strict: false);

        $this->assertFalse($fmt->strict);
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalMessage factories
    // ────────────────────────────────────────────────────────────────────────

    public function test_internal_message_system_factory(): void
    {
        $msg = InternalMessage::system('You are a helpful assistant.');

        $this->assertSame(Role::System, $msg->role);
        $this->assertSame('You are a helpful assistant.', $msg->content);
        $this->assertFalse($msg->isMultimodal());
    }

    public function test_internal_message_user_factory(): void
    {
        $msg = InternalMessage::user('Hello!');

        $this->assertSame(Role::User, $msg->role);
        $this->assertSame('Hello!', $msg->content);
        $this->assertFalse($msg->isMultimodal());
    }

    public function test_internal_message_assistant_factory_with_content(): void
    {
        $msg = InternalMessage::assistant('I can help with that.');

        $this->assertSame(Role::Assistant, $msg->role);
        $this->assertSame('I can help with that.', $msg->content);
        $this->assertSame([], $msg->toolCalls);
    }

    public function test_internal_message_assistant_factory_with_null_content_and_tool_calls(): void
    {
        $toolCall = new ToolCall(id: 'call_1', name: 'get_weather', arguments: ['city' => 'BA']);
        $msg = InternalMessage::assistant(null, [$toolCall]);

        $this->assertNull($msg->content);
        $this->assertCount(1, $msg->toolCalls);
        $this->assertSame($toolCall, $msg->toolCalls[0]);
    }

    public function test_internal_message_tool_factory_from_ok_result(): void
    {
        $result = ToolResult::ok('call_1', 'get_weather', 'Sunny, 22°C');
        $msg = InternalMessage::tool($result);

        $this->assertSame(Role::Tool, $msg->role);
        $this->assertSame('Sunny, 22°C', $msg->content);
        $this->assertSame('call_1', $msg->toolCallId);
        $this->assertSame('get_weather', $msg->name);
    }

    public function test_internal_message_tool_factory_json_encodes_array_content(): void
    {
        // When ToolResult::content is an array, InternalMessage::tool() must
        // JSON-encode it rather than passing the array as-is.
        $data = ['temp' => 22, 'unit' => 'C'];
        $result = ToolResult::ok('call_2', 'data_tool', $data);
        $msg = InternalMessage::tool($result);

        $this->assertSame(json_encode($data), $msg->content);
    }

    public function test_internal_message_user_with_parts_factory(): void
    {
        $text = ContentPart::text('What is this?');
        $img  = ContentPart::imageUrl('https://example.com/img.jpg');
        $msg  = InternalMessage::userWithParts($text, $img);

        $this->assertSame(Role::User, $msg->role);
        $this->assertTrue($msg->isMultimodal());
        $this->assertIsArray($msg->content);
        $this->assertCount(2, $msg->content);
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalMessage::userWithImage — branch: $asUrl = true (no network)
    // ────────────────────────────────────────────────────────────────────────

    public function test_user_with_image_as_url_returns_image_url_part(): void
    {
        // When asUrl=true the image is sent as a URL ContentPart without any fetch.
        $msg = InternalMessage::userWithImage('Describe this', 'https://example.com/cat.jpg', asUrl: true);

        $this->assertTrue($msg->isMultimodal());
        $parts = $msg->content;
        $this->assertCount(2, $parts);
        $this->assertSame(ContentPartType::Text, $parts[0]->type);
        $this->assertSame('Describe this', $parts[0]->value);
        $this->assertSame(ContentPartType::ImageUrl, $parts[1]->type);
        $this->assertSame('https://example.com/cat.jpg', $parts[1]->value);
        // NOTE: The URL-fetch branch (asUrl=false + URL) is intentionally NOT tested
        // offline because it calls file_get_contents() on a remote URL.
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalMessage::userWithImage — branch: local file path
    // ────────────────────────────────────────────────────────────────────────

    public function test_user_with_image_local_file_encodes_to_base64_and_detects_mime(): void
    {
        // Create a real temp PNG file (minimal 1×1 PNG header bytes)
        $pngData = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $tmpPath = sys_get_temp_dir() . '/macro_llm_test_' . uniqid() . '.png';
        file_put_contents($tmpPath, $pngData);
        $this->tempFiles[] = $tmpPath;

        $msg = InternalMessage::userWithImage('What is this image?', $tmpPath);

        $this->assertTrue($msg->isMultimodal());
        $parts = $msg->content;
        $this->assertCount(2, $parts);

        $textPart  = $parts[0];
        $imagePart = $parts[1];

        $this->assertSame(ContentPartType::Text, $textPart->type);
        $this->assertSame('What is this image?', $textPart->value);

        $this->assertSame(ContentPartType::ImageBase64, $imagePart->type);
        // MIME type must be inferred from .png extension
        $this->assertSame('image/png', $imagePart->mimeType);
        // The base64 value must match what the file contains
        $this->assertSame(base64_encode($pngData), $imagePart->value);
    }

    #[DataProvider('mimeDetectionProvider')]
    public function test_user_with_image_infers_correct_mime_from_extension(
        string $extension,
        string $expectedMime,
    ): void {
        $tmpPath = sys_get_temp_dir() . '/macro_llm_test_' . uniqid() . '.' . $extension;
        file_put_contents($tmpPath, 'fake-image-bytes');
        $this->tempFiles[] = $tmpPath;

        $msg   = InternalMessage::userWithImage('test', $tmpPath);
        $parts = $msg->content;
        $this->assertSame($expectedMime, $parts[1]->mimeType);
    }

    public static function mimeDetectionProvider(): array
    {
        return [
            'jpeg'  => ['jpg',  'image/jpeg'],
            'jpeg2' => ['jpeg', 'image/jpeg'],
            'png'   => ['png',  'image/png'],
            'gif'   => ['gif',  'image/gif'],
            'webp'  => ['webp', 'image/webp'],
            'unknown extension falls back to image/jpeg' => ['bmp', 'image/jpeg'],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalMessage::userWithImage — branch: raw base64 string
    // ────────────────────────────────────────────────────────────────────────

    public function test_user_with_image_raw_base64_uses_provided_mime_type(): void
    {
        // If the string is not an existing file path and not a URL, it is treated
        // as raw base64 content. mimeType must be provided; default is image/jpeg.
        $raw = base64_encode('not-a-real-image-but-valid-base64');

        $msg   = InternalMessage::userWithImage('Analyze this', $raw, mimeType: 'image/png');
        $parts = $msg->content;

        $this->assertSame(ContentPartType::ImageBase64, $parts[1]->type);
        $this->assertSame($raw, $parts[1]->value);
        $this->assertSame('image/png', $parts[1]->mimeType);
    }

    public function test_user_with_image_raw_base64_without_mime_defaults_to_jpeg(): void
    {
        $raw = base64_encode('fake-bytes');
        // No mimeType provided, no file, not a URL → default image/jpeg
        $msg   = InternalMessage::userWithImage('Analyze', $raw);
        $parts = $msg->content;

        $this->assertSame('image/jpeg', $parts[1]->mimeType);
    }

    // ────────────────────────────────────────────────────────────────────────
    // isMultimodal
    // ────────────────────────────────────────────────────────────────────────

    public function test_is_multimodal_true_when_content_is_array(): void
    {
        $msg = InternalMessage::userWithParts(ContentPart::text('hi'));
        $this->assertTrue($msg->isMultimodal());
    }

    public function test_is_multimodal_false_when_content_is_string(): void
    {
        $msg = InternalMessage::user('hi');
        $this->assertFalse($msg->isMultimodal());
    }

    public function test_is_multimodal_false_when_content_is_null(): void
    {
        $msg = InternalMessage::assistant(null);
        $this->assertFalse($msg->isMultimodal());
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalRequest — immutability invariants
    // ────────────────────────────────────────────────────────────────────────

    public function test_internal_request_with_messages_returns_new_instance(): void
    {
        $tool = new ToolDefinition(
            name: 't',
            description: 'd',
            parameters: [],
            callable: fn() => '',
        );
        $fmt = ResponseFormat::json();
        $original = new InternalRequest(
            messages: [InternalMessage::user('hello')],
            tools: [$tool],
            stream: true,
            responseFormat: $fmt,
        );

        $newMessages = [InternalMessage::user('goodbye')];
        $updated = $original->withMessages($newMessages);

        // Must be a different instance
        $this->assertNotSame($original, $updated);
        // Messages updated
        $this->assertSame($newMessages, $updated->messages);
        // All other fields preserved
        $this->assertSame($original->tools, $updated->tools);
        $this->assertSame($original->stream, $updated->stream);
        $this->assertSame($original->responseFormat, $updated->responseFormat);
        $this->assertSame($original->configOverride, $updated->configOverride);
    }

    public function test_internal_request_appended_returns_new_instance_with_merged_messages(): void
    {
        $tool = new ToolDefinition(
            name: 't2',
            description: 'd2',
            parameters: [],
            callable: fn() => '',
        );
        $fmt = ResponseFormat::jsonSchema('s', []);
        $original = new InternalRequest(
            messages: [InternalMessage::user('first')],
            tools: [$tool],
            stream: false,
            responseFormat: $fmt,
        );

        $extra = InternalMessage::assistant('response');
        $appended = $original->appended($extra);

        $this->assertNotSame($original, $appended);
        // Original untouched
        $this->assertCount(1, $original->messages);
        // Appended has both
        $this->assertCount(2, $appended->messages);
        $this->assertSame($original->messages[0], $appended->messages[0]);
        $this->assertSame($extra, $appended->messages[1]);
        // Other fields preserved
        $this->assertSame($original->tools, $appended->tools);
        $this->assertSame($original->stream, $appended->stream);
        $this->assertSame($original->responseFormat, $appended->responseFormat);
    }

    public function test_internal_request_appended_multiple_messages_at_once(): void
    {
        $original = new InternalRequest(messages: [InternalMessage::user('hi')]);
        $a = InternalMessage::assistant('yes');
        $b = InternalMessage::user('follow-up');

        $appended = $original->appended($a, $b);

        $this->assertCount(3, $appended->messages);
    }

    // ────────────────────────────────────────────────────────────────────────
    // InternalResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_internal_response_has_tool_calls_false_when_empty(): void
    {
        $response = new InternalResponse(
            content: 'Hello',
            finishReason: FinishReason::Stop,
        );

        $this->assertFalse($response->hasToolCalls());
    }

    public function test_internal_response_has_tool_calls_true_when_populated(): void
    {
        $toolCall = new ToolCall(id: 'call_1', name: 'ping', arguments: []);
        $response = new InternalResponse(
            content: null,
            finishReason: FinishReason::ToolCalls,
            toolCalls: [$toolCall],
        );

        $this->assertTrue($response->hasToolCalls());
    }

    public function test_internal_response_extra_field_is_preserved(): void
    {
        $extra = ['provider_specific_field' => 'value'];
        $response = new InternalResponse(
            content: 'text',
            finishReason: FinishReason::Stop,
            extra: $extra,
        );

        $this->assertSame($extra, $response->extra);
    }

    // ────────────────────────────────────────────────────────────────────────
    // StreamChunk
    // ────────────────────────────────────────────────────────────────────────

    public function test_stream_chunk_holds_all_fields(): void
    {
        $response = new InternalResponse(content: 'final', finishReason: FinishReason::Stop);
        $chunk = new StreamChunk(delta: ' world', index: 3, finished: true, response: $response);

        $this->assertSame(' world', $chunk->delta);
        $this->assertSame(3, $chunk->index);
        $this->assertTrue($chunk->finished);
        $this->assertSame($response, $chunk->response);
    }

    public function test_stream_chunk_defaults_finished_to_false_and_response_to_null(): void
    {
        $chunk = new StreamChunk(delta: 'hello', index: 0);

        $this->assertFalse($chunk->finished);
        $this->assertNull($chunk->response);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ImageSize enum
    // ────────────────────────────────────────────────────────────────────────

    public function test_image_size_enum_values(): void
    {
        $this->assertSame('square', ImageSize::Square->value);
        $this->assertSame('portrait', ImageSize::Portrait->value);
        $this->assertSame('landscape', ImageSize::Landscape->value);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ImageRequest
    // ────────────────────────────────────────────────────────────────────────

    public function test_image_request_defaults(): void
    {
        $req = new ImageRequest(prompt: 'A sunset');

        $this->assertSame('A sunset', $req->prompt);
        $this->assertSame(ImageSize::Square, $req->size);
        $this->assertNull($req->quality);
        $this->assertSame(1, $req->n);
        $this->assertNull($req->model);
    }

    public function test_image_request_explicit_fields(): void
    {
        $req = new ImageRequest(
            prompt: 'A cat',
            size: ImageSize::Landscape,
            quality: 'hd',
            n: 2,
            model: 'dall-e-3',
        );

        $this->assertSame(ImageSize::Landscape, $req->size);
        $this->assertSame('hd', $req->quality);
        $this->assertSame(2, $req->n);
        $this->assertSame('dall-e-3', $req->model);
    }

    // ────────────────────────────────────────────────────────────────────────
    // ImageResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_image_response_first_returns_first_image(): void
    {
        $response = new ImageResponse(images: ['base64img1', 'base64img2']);

        $this->assertSame('base64img1', $response->first());
    }

    public function test_image_response_first_returns_null_when_empty(): void
    {
        $response = new ImageResponse(images: []);

        $this->assertNull($response->first());
    }

    public function test_image_response_store_writes_decoded_content_to_file(): void
    {
        $raw = 'fake-binary-image-data';
        $b64 = base64_encode($raw);
        $response = new ImageResponse(images: [$b64]);

        $tmpPath = sys_get_temp_dir() . '/macro_llm_img_test_' . uniqid() . '.png';
        $this->tempFiles[] = $tmpPath;
        $response->store($tmpPath);

        $this->assertFileExists($tmpPath);
        $this->assertSame($raw, file_get_contents($tmpPath));
    }

    public function test_image_response_store_writes_empty_file_when_no_images(): void
    {
        $response = new ImageResponse(images: []);
        $tmpPath = sys_get_temp_dir() . '/macro_llm_img_empty_' . uniqid() . '.png';
        $this->tempFiles[] = $tmpPath;

        $response->store($tmpPath);

        $this->assertFileExists($tmpPath);
        $this->assertSame('', file_get_contents($tmpPath));
    }

    // ────────────────────────────────────────────────────────────────────────
    // AudioRequest
    // ────────────────────────────────────────────────────────────────────────

    public function test_audio_request_defaults(): void
    {
        $req = new AudioRequest(text: 'Hello world');

        $this->assertSame('Hello world', $req->text);
        $this->assertNull($req->voice);
        $this->assertNull($req->instructions);
        $this->assertNull($req->format);
        $this->assertNull($req->model);
    }

    public function test_audio_request_explicit_fields(): void
    {
        $req = new AudioRequest(
            text: 'Say this.',
            voice: 'alloy',
            instructions: 'Speak slowly.',
            format: 'mp3',
            model: 'tts-1',
        );

        $this->assertSame('alloy', $req->voice);
        $this->assertSame('Speak slowly.', $req->instructions);
        $this->assertSame('mp3', $req->format);
        $this->assertSame('tts-1', $req->model);
    }

    // ────────────────────────────────────────────────────────────────────────
    // AudioResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_audio_response_store_writes_raw_content_to_file(): void
    {
        $raw = "\x00\xFF\xAB\xCD audio bytes";
        $response = new AudioResponse(content: $raw, format: 'mp3');

        $tmpPath = sys_get_temp_dir() . '/macro_llm_audio_' . uniqid() . '.mp3';
        $this->tempFiles[] = $tmpPath;
        $response->store($tmpPath);

        $this->assertFileExists($tmpPath);
        // AudioResponse::store() writes raw binary, not base64-decoded
        $this->assertSame($raw, file_get_contents($tmpPath));
    }

    public function test_audio_response_holds_format(): void
    {
        $r = new AudioResponse(content: 'data', format: 'flac');

        $this->assertSame('flac', $r->format);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TranscriptionRequest
    // ────────────────────────────────────────────────────────────────────────

    public function test_transcription_request_defaults(): void
    {
        $req = new TranscriptionRequest(filePath: '/tmp/audio.mp3');

        $this->assertSame('/tmp/audio.mp3', $req->filePath);
        $this->assertNull($req->language);
        $this->assertNull($req->model);
    }

    public function test_transcription_request_explicit_fields(): void
    {
        $req = new TranscriptionRequest(filePath: '/tmp/a.wav', language: 'es', model: 'whisper-1');

        $this->assertSame('es', $req->language);
        $this->assertSame('whisper-1', $req->model);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TranscriptionResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_transcription_response_text_and_to_string(): void
    {
        $resp = new TranscriptionResponse(text: 'Hello there.', segments: null);

        $this->assertSame('Hello there.', $resp->text);
        $this->assertSame('Hello there.', (string) $resp);
    }

    public function test_transcription_response_with_segments(): void
    {
        $segments = [['speaker' => 'A', 'start' => 0.0, 'end' => 1.5, 'text' => 'Hello']];
        $resp = new TranscriptionResponse(text: 'Hello', segments: $segments);

        $this->assertSame($segments, $resp->segments);
    }

    // ────────────────────────────────────────────────────────────────────────
    // EmbeddingRequest
    // ────────────────────────────────────────────────────────────────────────

    public function test_embedding_request_defaults(): void
    {
        $req = new EmbeddingRequest(inputs: ['hello', 'world']);

        $this->assertSame(['hello', 'world'], $req->inputs);
        $this->assertNull($req->dimensions);
        $this->assertNull($req->model);
    }

    public function test_embedding_request_explicit_fields(): void
    {
        $req = new EmbeddingRequest(inputs: ['text'], dimensions: 1536, model: 'text-embedding-3-small');

        $this->assertSame(1536, $req->dimensions);
        $this->assertSame('text-embedding-3-small', $req->model);
    }

    // ────────────────────────────────────────────────────────────────────────
    // EmbeddingResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_embedding_response_holds_embeddings_and_usage(): void
    {
        $usage = new Usage(10, 0, 10);
        $embeddings = [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]];
        $resp = new EmbeddingResponse(embeddings: $embeddings, usage: $usage);

        $this->assertSame($embeddings, $resp->embeddings);
        $this->assertSame($usage, $resp->usage);
    }

    public function test_embedding_response_default_usage_is_zero(): void
    {
        $resp = new EmbeddingResponse(embeddings: []);

        $this->assertSame(0, $resp->usage->promptTokens);
        $this->assertSame(0, $resp->usage->totalTokens);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RerankingRequest
    // ────────────────────────────────────────────────────────────────────────

    public function test_reranking_request_defaults(): void
    {
        $req = new RerankingRequest(query: 'best pizza', documents: ['doc1', 'doc2']);

        $this->assertSame('best pizza', $req->query);
        $this->assertSame(['doc1', 'doc2'], $req->documents);
        $this->assertNull($req->limit);
        $this->assertNull($req->model);
    }

    public function test_reranking_request_explicit_fields(): void
    {
        $req = new RerankingRequest(
            query: 'q',
            documents: ['d'],
            limit: 5,
            model: 'rerank-english-v3.0',
        );

        $this->assertSame(5, $req->limit);
        $this->assertSame('rerank-english-v3.0', $req->model);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RankedDocument
    // ────────────────────────────────────────────────────────────────────────

    public function test_ranked_document_holds_all_fields(): void
    {
        $doc = new RankedDocument(index: 2, document: 'The quick brown fox', score: 0.97);

        $this->assertSame(2, $doc->index);
        $this->assertSame('The quick brown fox', $doc->document);
        $this->assertSame(0.97, $doc->score);
    }

    // ────────────────────────────────────────────────────────────────────────
    // RerankingResponse
    // ────────────────────────────────────────────────────────────────────────

    public function test_reranking_response_first_returns_top_result(): void
    {
        $doc1 = new RankedDocument(index: 0, document: 'Best match', score: 0.99);
        $doc2 = new RankedDocument(index: 1, document: 'Second best', score: 0.75);
        $resp = new RerankingResponse(results: [$doc1, $doc2]);

        $this->assertSame($doc1, $resp->first());
    }

    public function test_reranking_response_first_returns_null_when_empty(): void
    {
        $resp = new RerankingResponse(results: []);

        $this->assertNull($resp->first());
    }

    public function test_reranking_response_holds_all_results(): void
    {
        $docs = [
            new RankedDocument(0, 'a', 0.9),
            new RankedDocument(1, 'b', 0.8),
            new RankedDocument(2, 'c', 0.7),
        ];
        $resp = new RerankingResponse(results: $docs);

        $this->assertCount(3, $resp->results);
        $this->assertSame($docs, $resp->results);
    }
}
