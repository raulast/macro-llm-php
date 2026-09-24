# Tool approval

A tool that deletes a record is executed the moment the model asks for it. This is how you make one wait for a human.

## Marking a tool as dangerous

The tool declares its own risk, because the tool is what knows whether it destroys something:

```php
use MacroLLM\Tool\ToolDefinition;

$deleteRecord = new ToolDefinition(
    name: 'delete_record',
    description: 'Deletes a record permanently.',
    parameters: [
        'type' => 'object',
        'properties' => ['id' => ['type' => 'string']],
        'required' => ['id'],
    ],
    callable: fn (array $args): string => $records->delete($args['id']),
    requiresApproval: true,        // ← the call waits for a decision
);
```

## Answering the request

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Approval\Decision;
use MacroLLM\Approval\PendingApproval;

$agent = $llm->agent(new AgentConfig(
    tools: [$deleteRecord],
    approveToolCalls: function (PendingApproval $pending): Decision {
        // $pending->toolName, $pending->arguments, $pending->description, $pending->summary()
        return $confirmed($pending) ? Decision::Approve : Decision::Reject;
    },
));

$response = $agent->run('Delete record 42');
```

The approver receives the **arguments**, not only the tool name, because a decision made without seeing what the tool
is about to do is not really a decision. `$pending->summary()` gives a one-line form fit for a prompt or a log.

## What happens on each answer

| Answer | Result |
| --- | --- |
| `Decision::Approve` | The call runs — and its arguments are still validated against the schema first. Approval is not a substitute for validation. |
| `Decision::Reject` | The call does not run. The model receives it as a **tool error result** and the loop continues, so it can try another approach or explain why it needs this one. |
| No approver configured | `ToolApprovalRequiredException` is raised. The call neither runs nor is silently denied. |

**The last row is the important one.** A quiet denial would let the agent keep working while the person who marked the
tool as dangerous never learned it was about to fire. Marking a tool exists so that a human hears about it.

## Resuming after a raised decision

The exception carries the conversation, so a caller can continue:

```php
try {
    $response = $agent->run('Delete record 42');
} catch (ToolApprovalRequiredException $e) {
    // $e->pending  — toolName, arguments, description
    // $e->messages — the conversation up to and including the model's request

    present($e->pending);                        // ask the human, store the answer, whatever the app needs

    $response = $llm->agent(new AgentConfig(
        tools: [$deleteRecord],
        approveToolCalls: fn (PendingApproval $p): Decision => Decision::Approve,
    ))->run(new InternalRequest(messages: $e->messages));
}
```

**Stated plainly, because it is a limitation rather than a detail:** the resume is a **replay**. The model is asked
again with the conversation so far, and it re-issues the call — which is what a model does when it sees its own tool
call unanswered. The pending call's exact arguments are on the exception, so an approver that must match one specific
request can compare against them rather than approving whatever arrives next.
