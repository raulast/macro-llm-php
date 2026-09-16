<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent\Memory;

use MacroLLM\Agent\Memory\InMemoryMemory;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\Role;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolCall;

final class InMemoryMemoryTest extends TestCase
{
    // ── accumulate ──────────────────────────────────────────────────────────

    public function test_history_is_empty_before_anything_is_appended(): void
    {
        $this->assertSame([], (new InMemoryMemory())->getHistory());
    }

    public function test_append_accumulates_in_insertion_order(): void
    {
        $memory = new InMemoryMemory();

        $user      = InternalMessage::user('hello');
        $assistant = InternalMessage::assistant('hi there');
        $system    = InternalMessage::system('be nice');

        $memory->append($user);
        $memory->append($assistant);
        $memory->append($system);

        $this->assertSame([$user, $assistant, $system], $memory->getHistory());
    }

    public function test_append_preserves_message_identity_not_a_copy(): void
    {
        $memory  = new InMemoryMemory();
        $message = InternalMessage::user('hello');

        $memory->append($message);

        $this->assertSame($message, $memory->getHistory()[0]);
    }

    public function test_duplicate_messages_are_kept_separately(): void
    {
        $memory = new InMemoryMemory();

        $memory->append(InternalMessage::user('same'));
        $memory->append(InternalMessage::user('same'));

        $this->assertCount(2, $memory->getHistory());
    }

    public function test_tool_calls_and_tool_messages_round_trip(): void
    {
        $memory = new InMemoryMemory();

        $withCalls = InternalMessage::assistant(null, [
            new ToolCall('tc_1', 'get_weather', ['city' => 'Madrid']),
        ]);
        $toolMessage = new InternalMessage(
            role: Role::Tool,
            content: 'Sunny',
            toolCallId: 'tc_1',
            name: 'get_weather',
        );

        $memory->append($withCalls);
        $memory->append($toolMessage);

        $history = $memory->getHistory();

        $this->assertSame('get_weather', $history[0]->toolCalls[0]->name);
        $this->assertSame(['city' => 'Madrid'], $history[0]->toolCalls[0]->arguments);
        $this->assertSame('tc_1', $history[1]->toolCallId);
        $this->assertSame('get_weather', $history[1]->name);
    }
    // ── clear ───────────────────────────────────────────────────────────────

    public function test_clear_empties_the_buffer(): void
    {
        $memory = new InMemoryMemory();
        $memory->append(InternalMessage::user('hello'));
        $memory->append(InternalMessage::assistant('hi'));

        $memory->clear();

        $this->assertSame([], $memory->getHistory());
    }

    public function test_clear_is_idempotent_and_append_still_works_afterwards(): void
    {
        $memory = new InMemoryMemory();
        $memory->append(InternalMessage::user('first'));

        $memory->clear();
        $memory->clear();

        $this->assertSame([], $memory->getHistory());

        $memory->append(InternalMessage::user('second'));

        $this->assertSame(['second'], array_map(
            static fn(InternalMessage $m): mixed => $m->content,
            $memory->getHistory(),
        ));
    }

    public function test_clear_on_an_empty_buffer_is_safe(): void
    {
        $memory = new InMemoryMemory();

        $memory->clear();

        $this->assertSame([], $memory->getHistory());
    }

    // ── returned array is a value copy ──────────────────────────────────────

    public function test_mutating_the_returned_history_does_not_change_the_buffer(): void
    {
        $memory = new InMemoryMemory();
        $memory->append(InternalMessage::user('hello'));

        $history = $memory->getHistory();
        $history[] = InternalMessage::user('injected');
        array_shift($history);

        // PHP arrays are value types — the buffer is unaffected by caller mutation.
        $this->assertCount(1, $memory->getHistory());
        $this->assertSame('hello', $memory->getHistory()[0]->content);
    }

    // ── isolation between instances ─────────────────────────────────────────

    public function test_instances_do_not_share_state(): void
    {
        $first  = new InMemoryMemory();
        $second = new InMemoryMemory();

        $first->append(InternalMessage::user('only in first'));

        $this->assertCount(1, $first->getHistory());
        $this->assertSame([], $second->getHistory());
    }
}
