<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent\Memory;

use MacroLLM\Agent\Memory\SqliteMemory;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;

/**
 * SQLite-backed memory. Uses a real temp database file — no mocking — so that
 * schema creation, JSON round-tripping and conversation scoping are exercised
 * exactly as they run in production.
 */
final class SqliteMemoryTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/macro_llm_sqlite_' . uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function memory(string $conversationId = 'conv-1'): SqliteMemory
    {
        return new SqliteMemory($this->dbPath, $conversationId);
    }

    // ── Schema bootstrap ────────────────────────────────────────────────────

    public function test_construction_creates_the_database_file(): void
    {
        $this->assertFileDoesNotExist($this->dbPath);

        $this->memory();

        $this->assertFileExists($this->dbPath);
    }

    public function test_construction_is_idempotent_on_an_existing_database(): void
    {
        $this->memory()->append(InternalMessage::user('first'));

        // Second construction runs migrate() again against the populated file.
        $second = $this->memory();

        $this->assertCount(1, $second->getHistory());
    }

    public function test_a_new_conversation_starts_with_empty_history(): void
    {
        $this->assertSame([], $this->memory()->getHistory());
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

    public function test_ordering_follows_insertion_order_not_string_order(): void
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

    public function test_tool_calls_survive_the_json_column_round_trip(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::assistant(null, [
            new ToolCall('tc_1', 'get_weather', ['city' => 'Madrid', 'units' => 'metric']),
        ]));

        $call = $memory->getHistory()[0]->toolCalls[0];

        $this->assertSame('tc_1', $call->id);
        $this->assertSame('get_weather', $call->name);
        $this->assertSame(['city' => 'Madrid', 'units' => 'metric'], $call->arguments);
    }

    public function test_empty_tool_call_arguments_round_trip_as_an_empty_array(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::assistant(null, [new ToolCall('tc_1', 'ping', [])]));

        $this->assertSame([], $memory->getHistory()[0]->toolCalls[0]->arguments);
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

        $message = $memory->getHistory()[0];

        $this->assertSame(Role::Tool, $message->role);
        $this->assertSame('Sunny, 25C', $message->content);
        $this->assertSame('tc_1', $message->toolCallId);
        $this->assertSame('get_weather', $message->name);
    }

    public function test_messages_without_tool_call_id_or_name_stay_null(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('plain'));

        $message = $memory->getHistory()[0];

        $this->assertNull($message->toolCallId);
        $this->assertNull($message->name);
    }

    // ── cross-instance read ─────────────────────────────────────────────────

    public function test_a_second_instance_sees_history_written_by_the_first(): void
    {
        $this->memory()->append(InternalMessage::user('persisted'));

        $this->assertSame('persisted', $this->memory()->getHistory()[0]->content);
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

    // ── session isolation ───────────────────────────────────────────────────

    public function test_two_conversations_in_the_same_file_do_not_see_each_other(): void
    {
        $this->memory('conv-1')->append(InternalMessage::user('for one'));
        $this->memory('conv-2')->append(InternalMessage::user('for two'));

        $this->assertSame('for one', $this->memory('conv-1')->getHistory()[0]->content);
        $this->assertSame('for two', $this->memory('conv-2')->getHistory()[0]->content);
        $this->assertCount(1, $this->memory('conv-1')->getHistory());
    }

    public function test_clearing_one_conversation_leaves_the_other_intact(): void
    {
        $this->memory('conv-1')->append(InternalMessage::user('keep me'));
        $this->memory('conv-2')->append(InternalMessage::user('delete me'));

        $this->memory('conv-2')->clear();

        $this->assertSame([], $this->memory('conv-2')->getHistory());
        $this->assertCount(1, $this->memory('conv-1')->getHistory());
    }

    // ── clear ───────────────────────────────────────────────────────────────

    public function test_clear_empties_the_conversation(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('hello'));
        $memory->append(InternalMessage::assistant('hi'));

        $memory->clear();

        $this->assertSame([], $memory->getHistory());
    }

    public function test_clear_is_idempotent_and_append_still_works_afterwards(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('first'));

        $memory->clear();
        $memory->clear();

        $this->assertSame([], $memory->getHistory());

        $memory->append(InternalMessage::user('second'));

        $this->assertSame(
            ['second'],
            array_map(static fn(InternalMessage $m): mixed => $m->content, $memory->getHistory()),
        );
    }

    public function test_clear_does_not_delete_the_database_file(): void
    {
        $memory = $this->memory();
        $memory->append(InternalMessage::user('hello'));

        $memory->clear();

        // Only rows go away — the schema and file persist for the next run.
        $this->assertFileExists($this->dbPath);
        $this->assertSame([], $memory->getHistory());
    }
}
