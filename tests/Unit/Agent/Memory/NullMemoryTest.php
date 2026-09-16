<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Agent\Memory;

use MacroLLM\Agent\Memory\NullMemory;
use MacroLLM\Contract\ConversationMemoryInterface;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Tests\TestCase;

/**
 * NullMemory is the default for AgentConfig — it discards everything.
 * These tests exist because a silent change here would leak conversation
 * history into agents that explicitly opted out of persistence.
 */
final class NullMemoryTest extends TestCase
{
    public function test_implements_conversation_memory_interface(): void
    {
        $this->assertInstanceOf(ConversationMemoryInterface::class, new NullMemory());
    }

    // ── recall is always empty ──────────────────────────────────────────────

    public function test_recall_returns_empty_array_before_anything_is_appended(): void
    {
        $this->assertSame([], (new NullMemory())->getHistory());
    }

    public function test_recall_returns_empty_array_after_appending_messages(): void
    {
        $memory = new NullMemory();

        $memory->append(InternalMessage::user('hello'));
        $memory->append(InternalMessage::assistant('hi there'));
        $memory->append(InternalMessage::system('you are an assistant'));

        $this->assertSame([], $memory->getHistory());
    }

    public function test_recall_stays_empty_across_many_appends(): void
    {
        $memory = new NullMemory();

        for ($i = 0; $i < 50; $i++) {
            $memory->append(InternalMessage::user("message {$i}"));
        }

        $this->assertSame([], $memory->getHistory());
    }

    public function test_repeated_recall_calls_all_return_empty(): void
    {
        $memory = new NullMemory();
        $memory->append(InternalMessage::user('hello'));

        $this->assertSame([], $memory->getHistory());
        $this->assertSame([], $memory->getHistory());
        $this->assertSame([], $memory->getHistory());
    }

    // ── clear is idempotent ─────────────────────────────────────────────────

    public function test_clear_is_a_safe_no_op_when_nothing_was_appended(): void
    {
        $memory = new NullMemory();

        $memory->clear();

        $this->assertSame([], $memory->getHistory());
    }

    public function test_clear_keeps_recall_empty_after_appends(): void
    {
        $memory = new NullMemory();
        $memory->append(InternalMessage::user('hello'));

        $memory->clear();

        $this->assertSame([], $memory->getHistory());
    }

    // ── no hidden state across instances ────────────────────────────────────

    public function test_one_instance_never_observes_another_instances_writes(): void
    {
        $first = new NullMemory();
        $second = new NullMemory();

        $first->append(InternalMessage::user('leaked?'));

        $this->assertSame([], $second->getHistory());
    }
}
