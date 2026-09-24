<?php

declare(strict_types=1);

namespace MacroLLM\Approval;

/**
 * What a human decided about a tool call that asked for permission.
 *
 * Two cases on purpose. There is no "maybe": a suspended call is either allowed to run or it is not, and anything in
 * between would leave the loop guessing at what to do with the model's turn.
 */
enum Decision: string
{
    case Approve = 'approve';
    case Reject = 'reject';

    public function allowsExecution(): bool
    {
        return $this === self::Approve;
    }
}
