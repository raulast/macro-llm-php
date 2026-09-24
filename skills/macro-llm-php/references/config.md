# Configuration Reference

How to build the global `Config` object, configure each provider, and tune `AgentConfig` per agent.

## Config Structure

```php
Config::fromArray([
    'default_provider'    => 'openai',  // used when no provider specified
    'timeout'             => 30,         // global timeout seconds (1–300)
    'retries'             => 0,          // retry count (0–10); per-provider override via ProviderConfig
    'retry_delay_ms'      => 500,        // base delay ms for exponential backoff (500→1000→2000→...)
    'max_tool_iterations' => 10,         // agent loop max
    'providers' => [
        'openai' => [
            'api_key'        => '${OPENAI_API_KEY}', // ${VAR} expanded when Config is built
            'default_model'  => 'gpt-4o',
            'base_url'       => null,     // override for Azure OpenAI
            'timeout'        => null,     // ?int — null = use global timeout
            'retries'        => null,     // ?int — null = use global retries
            'retry_delay_ms' => null,     // ?int — null = use global retry_delay_ms
            'extra_headers'  => [],
        ],
        'ollama' => [
            'api_key'       => null,     // optional for local providers
            'default_model' => 'llama3.2',
            'base_url'      => 'http://localhost:11434/v1', // developer-specific URL
        ],
        'opencode-zen-go' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'deepseek-v3-0324',
        ],
        'opencode-zen-go-anthropic' => [
            'api_key'       => '${OPENCODE_ZEN_API_KEY}',
            'default_model' => 'MiniMax-M3',
        ],
    ],
]);
```

### `Config::fromArray()` keys

The canonical shape above is also the documented default for every key; the Default column mirrors it.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `default_provider` | `string` | `'openai'` | Provider used when a request does not name one explicitly. |
| `timeout` | `int` | `30` | Global request timeout in seconds (1–300). |
| `retries` | `int` | `0` | Retry attempts after a failure (0–10); overridable per provider. |
| `retry_delay_ms` | `int` | `500` | Base delay in ms for exponential backoff (500 → 1000 → 2000 → ...). |
| `max_tool_iterations` | `int` | `10` | Maximum iterations of the agent tool-call loop. |
| `providers` | `array<string, array>` | `[]` | Map of provider name to its raw provider configuration. |

### `ProviderConfig` fields

| Field | Type | Default | Meaning |
|---|---|---|---|
| `api_key` | `?string` | `null` | Credential for the provider; optional for local providers such as `ollama`. |
| `default_model` | `string` | none | Model used when the request does not name one. |
| `base_url` | `?string` | `null` | Base URL override, for example Azure OpenAI or a local `ollama` endpoint. |
| `timeout` | `?int` | `null` | Per-provider timeout override in seconds. |
| `retries` | `?int` | `null` | Per-provider retry count override. |
| `retry_delay_ms` | `?int` | `null` | Per-provider backoff base delay override in ms. |
| `extra_headers` | `array` | `[]` | Extra HTTP headers sent with every request to that provider. |
| `fallback` | `array` | `[]` | Provider names to try, in order, when this one fails in a way another provider might survive. **Empty by default: no hop happens unless you ask for one.** See `providers.md` for which failures qualify. |

The three nullable numeric fields — `timeout`, `retries`, and `retry_delay_ms` — are typed `?int`, where `null` means "not overridden — the global `Config` value wins".

### Environment variables

`${VAR}` placeholders are expanded at construction by `EnvResolver`. An undefined variable is left verbatim, so a missing value surfaces as a literal `${VAR}` string instead of silently becoming empty. Load your `.env` before building `Config`, otherwise the expansion sees no value.

## AgentConfig Options

```php
new AgentConfig(
    provider:       'openai',          // overrides global default; null = use global
    systemPrompt:   'You are...',      // prepended before skill prompts
    tools:          [$toolDefinition], // direct ToolDefinition[] — lower priority than skills
    skillNames:     ['translator'],    // resolved from SkillRegistry
    skillSeparator: "\n\n",            // separator between composed skill prompts
    maxIterations:  10,                // max tool-call loop iterations
    memory:         new InMemoryMemory(), // NullMemory (default) or InMemoryMemory
    onStep:         fn(AgentStep $s) => log($s), // optional; null = disabled (default)
);
```

### `AgentConfig` options

| Option | Type | Default | Meaning |
|---|---|---|---|
| `provider` | `?string` | `null` | Overrides the global default; `null` = use global. |
| `systemPrompt` | `?string` | `null` | Prepended before skill prompts. |
| `tools` | `ToolDefinition[]` | `[]` | Direct tool definitions — lower priority than skills. |
| `skillNames` | `string[]` | `[]` | Skill names resolved from the `SkillRegistry`. |
| `skillSeparator` | `string` | `"\n\n"` | Separator between composed skill prompts. |
| `maxIterations` | `int` | `10` | Maximum iterations of the tool-call loop. |
| `memory` | `Memory` | `NullMemory` | Conversation memory driver; `NullMemory` means no persistence. |
| `onStep` | `?Closure` | `null` | Step callback for the agent loop; `null` = disabled. |

**Provider resolution order** (low → high priority):
1. Global `Config::defaultProvider()`
2. `AgentConfig::provider`
3. `Skill::getConfigOverride()::defaultProvider()`

**System prompt order** (first → last in request):
1. `AgentConfig::systemPrompt`
2. Skill prompts in composition order, joined by `skillSeparator`

**Tool resolution** (priority order):
1. Skill tools (in composition order) — `SkillToolConflictException` if two skills share a name
2. `AgentConfig::tools` — silently ignored if name conflicts with a skill tool

## Integration notes

`config/macro-llm.php` in the Slim integration is created by you, inside your own project. It is not auto-published, unlike Laravel's `vendor:publish` step: there is no artisan command that writes the file for you, so copy the structure from this reference and keep it in your application's config directory.
