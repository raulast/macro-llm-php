<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

use MacroLLM\Approval\PendingApproval;
use MacroLLM\Message\InternalMessage;

/**
 * A tool that requires approval was called, and nobody is configured to answer.
 *
 * This is a THROW rather than a denial result on purpose. Denying it quietly would let the agent keep running while
 * the human never learns that a tool they marked as dangerous was about to fire — and the whole point of marking it
 * is that a person hears about it.
 *
 * **Resuming.** `$messages` is the conversation up to and including the model's request, so a caller can carry on by
 * running the agent again with `new InternalRequest(messages: $e->messages)` and an approver configured on the
 * `AgentConfig`. The model re-issues the call, which is what a model does when it sees its own tool call unanswered,
 * and this time the approver answers it. The pending call's exact arguments are on the exception, so an approver that
 * needs to match the specific request can.
 */
final class ToolApprovalRequiredException extends MacroLLMException
{
    /** @param list<InternalMessage> $messages */
    public function __construct(
        public readonly PendingApproval $pending,
        public readonly array $messages,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<InternalMessage>  $messages
     */
    public static function noApprover(PendingApproval $pending, array $messages): self
    {
        return new self(
            pending: $pending,
            messages: $messages,
            message: sprintf(
                'The tool "%s" requires approval, and no approver is configured, so it did not run. Set '
                . 'AgentConfig::$approveToolCalls to receive these requests, or resume this conversation by running '
                . 'the agent again with this exception\'s $messages and an approver in place.',
                $pending->toolName,
            ),
        );
    }
}
