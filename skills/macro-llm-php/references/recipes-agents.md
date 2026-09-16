# Agent, Skill and Orchestration Recipes

Recipes for skills, agent-loop observability, conversation memory, vision, structured output, and conditional orchestration.

### Recipe 8: Skills — Inline Creation

```php
use MacroLLM\Skill\Skill;

$skill = Skill::create(
    name:         'translator',
    systemPrompt: 'You are a professional translator. Respond only in the target language.',
    tools:        ['detect_language', 'translate_text'], // tool names already in ToolRegistry
);

$llm->skills()->register($skill);

$agent = $llm->agent(new AgentConfig(
    provider:    'anthropic',
    skillNames:  ['translator'],
));
$response = $agent->run('Translate "Hello world" to French.');
```

### Recipe 9: Skills — DB Hydration

```php
// Hydrate from a database record (or any array):
$record = ['name' => 'support', 'system_prompt' => 'You are a support agent.', 'tools' => []];
$skill  = Skill::fromArray($record);
$llm->skills()->register($skill);
```

### Recipe 10: Skills — Dynamic Subclass

```php
use MacroLLM\Skill\Skill;

class SupportSkill extends Skill
{
    public function __construct(private readonly string $product) {}
    public function getName(): string        { return 'support'; }
    public function getSystemPrompt(): string {
        return "You are a support agent for {$this->product}. Be concise and helpful.";
    }
}

$llm->skills()->register(new SupportSkill('Laravel'));
```

### Recipe 10.5: Agent Step Callback (Observability)

`Agent` is `final` — the `onStep` closure in `AgentConfig` is the only mechanism for observing
loop events without modifying or extending the class.

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;

$agent = $llm->agent(new AgentConfig(
    provider:      'openai',
    maxIterations: 10,
    onStep: function (AgentStep $step): void {
        echo match ($step->type) {
            AgentStepType::LlmResponse   => "[{$step->iteration}] LLM → tool calls incoming\n",
            AgentStepType::ToolCall      => "[{$step->iteration}] → {$step->toolCall->name}(" . json_encode($step->toolCall->arguments) . ")\n",
            AgentStepType::ToolResult    => "[{$step->iteration}] ← " . json_encode($step->toolResult->content) . " [{$step->toolResult->status->value}]\n",
            AgentStepType::FinalResponse => "[{$step->iteration}] DONE: {$step->response->content}\n",
        };
    },
));

$response = $agent->run('What is the weather in Paris?');
```

**AgentStep fields per type:**

| Type | `response` | `toolCall` | `toolResult` |
|---|---|---|---|
| `LlmResponse` | ✓ (has tool calls) | — | — |
| `ToolCall` | — | ✓ | — |
| `ToolResult` | — | ✓ | ✓ |
| `FinalResponse` | ✓ (no tool calls) | — | — |

- `iteration` is 1-based, independent from the iteration guard counter.
- Exceptions thrown inside the callback propagate to the `agent->run()` caller.
- `onStep: null` (default) has zero overhead on the hot path.

### Recipe 11: Conversation Memory

```php
use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\Memory\InMemoryMemory;
use MacroLLM\Agent\Memory\SqliteMemory;
use MacroLLM\Agent\Memory\RedisMemory;
use MacroLLM\Agent\Memory\FileMemory;

// In-process (lost on restart)
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new InMemoryMemory(),
));

// Persistent — SQLite (no extra dependencies, uses PHP PDO)
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new SqliteMemory('/var/data/conversations.db', 'user-123'),
));

// Persistent — Redis
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new RedisMemory($redisClient, 'user-123', ttl: 3600),
));

// Persistent — File
$agent = $llm->agent(new AgentConfig(
    provider: 'openai',
    memory:   new FileMemory('/tmp/conv-user-123.json'),
));

$agent->run('My name is Ana and I love PHP.');
$response = $agent->run('What do you know about me?');
echo $response->content; // "Your name is Ana and you love PHP."
```

### Recipe 11.5: Vision / Multimodal

```php
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\ContentPart;

// Convenience factory — base64 by default (works with all providers)
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('What is in this image?', '/path/to/photo.jpg'),
]));

// From URL — fetches and converts to base64 automatically
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('Describe this', 'https://example.com/img.jpg'),
]));

// Send as URL (only for providers that support it — pass asUrl: true explicitly)
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithImage('Describe this', 'https://storage.googleapis.com/...', asUrl: true),
]));

// Full control via ContentPart
$response = $llm->chat(new InternalRequest([
    InternalMessage::userWithParts(
        ContentPart::text('Compare these two images:'),
        ContentPart::imageBase64($base64data, 'image/png'),
        ContentPart::imageBase64($base64data2, 'image/jpeg'),
    ),
]));
```

**Provider support:**
- `openai`, `anthropic`, `gemini`, `groq` — support `imageBase64`
- `imageUrl` — only works with providers that allow URL fetching (generally avoid; use base64)

### Recipe 11.6: Structured Output (JSON Schema)

```php
use MacroLLM\Message\ResponseFormat;

$request = new InternalRequest(
    messages: [InternalMessage::user('Extract: name and age from "Ana is 28 years old"')],
    responseFormat: ResponseFormat::jsonSchema('person', [
        'type'       => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'age'  => ['type' => 'integer'],
        ],
        'required'   => ['name', 'age'],
    ]),
);

$response = $llm->chat($request, 'openai');
$data = json_decode($response->content, true);
// ['name' => 'Ana', 'age' => 28]
```

**Note:** `ResponseFormat` is applied by OpenAI-compatible providers. Anthropic and Gemini ignore it silently — instruct via system prompt instead.

### Recipe 11.7: Conditional Orchestration

```php
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\AgentOutcome;

$orchestrator = new Orchestrator();

// Always runs (no condition)
$orchestrator->addAgent('classifier', $llm->agent(new AgentConfig(
    provider:     'openai',
    systemPrompt: 'Classify the input as "technical" or "general". Reply with one word only.',
)));

// Only runs if previous agent said "technical"
$orchestrator->addConditionalAgent(
    'technical-responder',
    $llm->agent(new AgentConfig(provider: 'openai', systemPrompt: 'You are a senior PHP engineer.')),
    fn(?AgentOutcome $prev) => $prev !== null && str_contains(strtolower($prev->response?->content ?? ''), 'technical'),
);

// Only runs if previous agent said "general"
$orchestrator->addConditionalAgent(
    'general-responder',
    $llm->agent(new AgentConfig(provider: 'openai', systemPrompt: 'You are a friendly assistant.')),
    fn(?AgentOutcome $prev) => $prev !== null && str_contains(strtolower($prev->response?->content ?? ''), 'general'),
);

$result = $orchestrator->dispatch('How do PHP fibers work?');
```

## See also

- `recipes-basic.md` — standalone setup, provider swapping, streaming, and tool calling.
- `recipes-advanced.md` — orchestration, MCP integration, framework wiring, and custom providers.
- `invariants.md` — the behavior contracts these recipes depend on.

