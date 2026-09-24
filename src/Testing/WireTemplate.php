<?php

declare(strict_types=1);

namespace MacroLLM\Testing;

/**
 * Turns a canonical answer into the shape one provider family puts on the wire.
 *
 * This is the piece that makes a fake ergonomic. Without it a consumer writing a test would have to reproduce their
 * provider's response format — nested keys, finish-reason spelling, where the tool arguments are hidden — which is
 * the provider adapter's job and exactly the friction a double exists to remove. With it, a test says
 * `->respondingWith('Hello!')` and the package owns the format.
 *
 * When a family is missing, {@see FakeGateway} refuses rather than approximating: a guessed payload would fail
 * inside the adapter, far from the test that caused it.
 */
interface WireTemplate
{
    /** The family's shape for a plain text answer. */
    public function text(string $content, string $model): string;

    /** The family's shape for a single tool call, with the arguments in the place that family hides them. */
    public function toolCall(string $toolName, array $arguments, string $toolCallId, string $model): string;
}
