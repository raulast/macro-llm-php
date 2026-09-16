<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent\Memory;

use MacroLLM\Agent\Memory\FileMemory;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;

/**
 * JSON-file-backed memory. Uses a real temp file so serialization, lazy file
 * creation and deletion are exercised exactly as they run in production.
 */
final class FileMemoryTest extends TestCase
{
    private string $filePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->filePath = sys_get_temp_dir() . '/macro_llm_file_memory_' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }

        parent::tearDown();
    }

    private function memory(): FileMemory
    {
        return new FileMemory($this->filePath);
    }

    // ── lazy file creation ──────────────────────────────────────────────────

    public function test_construction_does_not_create_the_file(): void
    {
        $this->memory();

        // Nothing is written until the first append.
        $this->assertFileDoesNotExist($this->filePath);
    }

    public function test_history_of_a_missing_file_is_empty(): void
    {
        $this->assertSame([], $this->memory()->getHistory());
    }

    public function test_first_append_creates_the_file(): void
    {
        $this->memory()->append(InternalMessage::user('hello'));

        $this->assertFileExists($this->filePath);
    }

    public function test_file_contents_are_pretty_printed_json(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('hello'));

        $raw = file_get_contents($this->filePath);

        $this->assertStringContainsString("\n", (string) $raw);
        $this->assertIsArray(json_decode((string) $raw, true));
    }

    // ── append + read back ──────────────────────────────────────────────────

    public function test_append_then_get_history_returns_the_message_in_order(): void
    {
        $memory = $this->memory();

        $memory->append(InternalMessage::user('hello'));
        $memory->append(InternalMessage::assistant('hi there'));

        $history = $memory->getHistory();

        $this->assertCount(2, $history);
        $this->assertSame(Role::User, $history[0]->role);
        $this->assertSame('hello', $history[0]->content);
        $this->assertSame(Role::Assistant, $history[1]->role);
        $this->assertSame('hi there', $history[1]->content);
    }

    public function test_ordering_follows_insertion_order(): void
    {
        $memory = $this->memory();

        foreach (['z', 'a', 'm'] as $content) {
            $memory->append(InternalMessage::user($content));
        }

        $this->assertSame(
            ['z', 'a', 'm'],
            array_map(static fn(InternalMessage $m): mixed => $m->content, $memory->getHistory()),
        );
    }

    public function test_assistant_message_with_null_content_round_trips(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::assistant(null, [new ToolCall('tc_1', 'ping', [])]));

        $history = $memory->getHistory();

        $this->assertNull($history[0]->content);
        $this->assertCount(1, $history[0]->toolCalls);
    }

    public function test_tool_calls_survive_the_json_round_trip(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::assistant(null, [
            new ToolCall('tc_1', 'get_weather', ['city' => 'Madrid', 'units' => 'metric']),
        ]));

        $call = $this->memory()->getHistory()[0]->toolCalls[0];

        $this->assertSame('tc_1', $call->id);
        $this->assertSame('get_weather', $call->name);
        $this->assertSame(['city' => 'Madrid', 'units' => 'metric'], $call->arguments);
    }

    public function test_empty_tool_call_arguments_round_trip_as_an_empty_array(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::assistant(null, [new ToolCall('tc_1', 'ping', [])]));

        $this->assertSame([], $this->memory()->getHistory()[0]->toolCalls[0]->arguments);
    }

    public function test_tool_message_keeps_its_tool_call_id_and_name(): void
    {
        $memory = $this->memory();
        $memory->append(new InternalMessage(
            role: Role::Tool,
            content: 'Sunny, 25C',
            toolCallId: 'tc_1',
            name: 'get_weather',
        ));

        $message = $this->memory()->getHistory()[0];

        $this->assertSame(Role::Tool, $message->role);
        $this->assertSame('Sunny, 25C', $message->content);
        $this->assertSame('tc_1', $message->toolCallId);
        $this->assertSame('get_weather', $message->name);
    }

    public function test_messages_without_tool_call_id_or_name_stay_null(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('plain'));

        $message = $this->memory()->getHistory()[0];

        $this->assertNull($message->toolCallId);
        $this->assertNull($message->name);
    }

    // ── cross-instance read ─────────────────────────────────────────────────

    public function test_a_second_instance_sees_history_written_by_the_first(): void
    {
        $this->memory()->append(InternalMessage::user('persisted'));

        $this->assertSame('persisted', $this->memory()->getHistory()[0]->content);
    }

    public function test_each_append_rewrites_the_whole_file(): void
    {
        $memory = $this->memory();

        $memory->append(InternalMessage::user('one'));
        $afterFirst = file_get_contents($this->filePath);

        $memory->append(InternalMessage::user('two'));
        $afterSecond = file_get_contents($this->filePath);

        // Documented characteristic: append() re-reads and rewrites the full
        // history, so the file grows with each call.
        $this->assertNotSame($afterFirst, $afterSecond);
        $this->assertCount(1, json_decode((string) $afterFirst, true));
        $this->assertCount(2, json_decode((string) $afterSecond, true));
    }

    public function test_history_persists_across_repeated_instantiations(): void
    {
        foreach (['one', 'two', 'three'] as $content) {
            $this->memory()->append(InternalMessage::user($content));
        }

        $this->assertSame(
            ['one', 'two', 'three'],
            array_map(static fn(InternalMessage $m): mixed => $m->content, $this->memory()->getHistory()),
        );
    }

    // ── clear ───────────────────────────────────────────────────────────────

    public function test_clear_deletes_the_file(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('hello'));

        $memory->clear();

        // FileMemory deletes rather than truncates — unlike SqliteMemory.
        $this->assertFileDoesNotExist($this->filePath);
        $this->assertSame([], $memory->getHistory());
    }

    public function test_clear_is_safe_when_the_file_never_existed(): void
    {
        $memory = $this->memory();

        $memory->clear();

        $this->assertFileDoesNotExist($this->filePath);
        $this->assertSame([], $memory->getHistory());
    }

    public function test_clear_is_idempotent_and_append_recreates_the_file(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('first'));

        $memory->clear();
        $memory->clear();

        $this->assertFileDoesNotExist($this->filePath);

        $memory->append(InternalMessage::user('second'));

        $this->assertFileExists($this->filePath);
        $this->assertSame('second', $memory->getHistory()[0]->content);
    }
}
