<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Feature;

use MacroLLM\Agent\AgentConfig;
use MacroLLM\Agent\AgentStep;
use MacroLLM\Agent\AgentStepType;
use MacroLLM\Agent\Memory\FileMemory;
use MacroLLM\Agent\Memory\InMemoryMemory;
use MacroLLM\Agent\Memory\SqliteMemory;
use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Orchestration\ErrorStrategy;
use MacroLLM\Orchestration\Orchestrator;
use MacroLLM\Orchestration\RoutingStrategy;
use MacroLLM\Skill\GenericSkill;
use MacroLLM\Skill\Skill;
use MacroLLM\Tests\Concerns\RequiresLiveTests;
use MacroLLM\Tests\Concerns\RequiresOllama;
use MacroLLM\Tests\Concerns\SkipsWithoutApiKey;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\ToolDefinition;

/**
 * Feature port of the scratch script .idea/ultimate.php (which stays in place).
 *
 * Run it against a locally running Ollama daemon:
 *   MACRO_LLM_LIVE_TESTS=1 composer test:feature
 *
 * Without MACRO_LLM_LIVE_TESTS=1 every test in this file skips: the first statement
 * of each test method is the live-test gate, so the default test
 * suite and a bare `vendor/bin/phpunit tests/Feature/` never touch the network.
 *
 * The scratch script bootstrapped 'minimax-m3:cloud', a cloud model that needs an
 * ollama.com sign-in on the local daemon. This port uses the locally installed
 * 'qwen3:1.7b' so the file stays usable once the endpoint variable is set; see
 * the model note next to self::MODEL.
 */
final class UltimateFeatureTest extends TestCase
{
    use RequiresLiveTests;
    use RequiresOllama;
    use SkipsWithoutApiKey;

    /**
     * Deviation from the source script: it used 'minimax-m3:cloud'. A cloud model
     * requires an ollama.com sign-in on the local daemon, which makes the ported
     * test unusable even when the live gate is on. 'qwen3:1.7b' is verified
     * installed and served locally by this machine's Ollama, so it is used instead.
     */
    private const MODEL = 'qwen3:1.7b';

    // ── Bootstrap (shared by every section) ────────────────────────────────

    private function defaultConfig(): Config
    {
        return Config::fromArray([
            'default_provider'    => 'ollama',
            'timeout'             => 30,
            'retries'             => 1,          // DT-1: retry enabled
            'retry_delay_ms'      => 200,
            'max_tool_iterations' => 5,
            'providers' => [
                'ollama' => [
                    'api_key'       => 'ollama-local-apikey',
                    'default_model' => self::MODEL,
                ],
            ],
        ]);
    }

    private function makeLlm(): MacroLLM
    {
        return MacroLLM::standalone($this->defaultConfig());
    }

    /**
     * Temp path for the memory-driver section, mirroring the scratch script's
     * per-process /tmp file names.
     */
    private function tempPath(string $prefix, string $extension): string
    {
        return sys_get_temp_dir() . '/' . $prefix . '_' . getmypid() . '.' . $extension;
    }

    // ── Basic chat ─────────────────────────────────────────────────────────

    public function test_basic_chat_returns_string_content_and_token_usage(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $response = $this->makeLlm()->chat(new InternalRequest([
            InternalMessage::user('Reply with exactly the word: PONG'),
        ]));

        $this->assertIsString($response->content);
        $this->assertGreaterThan(0, $response->usage->totalTokens);
    }

    // ── Streaming ──────────────────────────────────────────────────────────

    public function test_streaming_emits_chunks_and_a_final_response(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $chunks = 0;
        $fullText = '';
        $finalResponse = null;

        foreach ($this->makeLlm()->stream(new InternalRequest([
            InternalMessage::user('Count 1 to 5, one number per line.'),
        ])) as $chunk) {
            if ($chunk->finished) {
                $finalResponse = $chunk->response;
                break;
            }

            $fullText .= $chunk->delta;
            $chunks++;
        }

        // The source script asserted only that the final chunk carries a response.
        $this->assertNotNull($finalResponse);

        // Streamed responses are assembled locally with a zeroed Usage placeholder
        // (MacroLLM::stream() builds `usage: new Usage()`), so `totalTokens > 0` is
        // deliberately NOT asserted here: it would pin a non-contract.
        $this->assertNotSame('', (string) $finalResponse->content);
        $this->assertGreaterThan(0, $chunks);
    }

    // ── Tool calling + Agent loop ──────────────────────────────────────────

    public function test_agent_tool_calling_loop_completes_with_content(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $llm = $this->makeLlm();
        $callLog = [];   // Filled by the tool callable when the model calls the tool.

        $llm->tools()->register(new ToolDefinition(
            name: 'get_price',
            description: 'Returns the current price of a product.',
            parameters: [
                'type'       => 'object',
                'properties' => ['product' => ['type' => 'string']],
                'required'   => ['product'],
            ],
            callable: function (array $args) use (&$callLog): string {
                $callLog[] = $args['product'];

                return "Price of {$args['product']}: \$42";
            },
        ));

        $agent = $llm->agent(new AgentConfig(
            provider:      'ollama',
            maxIterations: 5,
            onStep: function (AgentStep $step): void {
                // The scratch script echoed tool calls here; PHPUnit output is not
                // used for assertions, so the callback is kept only as a live hook.
                if ($step->type === AgentStepType::ToolCall) {
                    $step->toolCall->name;
                }
            },
        ));

        $agentResponse = $agent->run('What is the price of "laptop"? Use the get_price tool.');

        // Whether the model actually invokes the tool is model behaviour, not a
        // package contract: the scratch script accepted both outcomes and printed
        // a warning when $callLog stayed empty. The stable contract is that the
        // agent loop terminates with usable content.
        $this->assertNotSame('', (string) $agentResponse->content);
    }

    // ── Memory drivers ─────────────────────────────────────────────────────

    public function test_memory_drivers_append_history_persist_and_clear(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is missing — cannot exercise SqliteMemory.');
        }

        // InMemoryMemory
        $inMemory = new InMemoryMemory();
        $inMemory->append(InternalMessage::user('hello'));
        $inMemory->append(InternalMessage::assistant('world'));
        $this->assertCount(2, $inMemory->getHistory());
        $this->assertSame('hello', $inMemory->getHistory()[0]->content);
        $this->assertSame('world', $inMemory->getHistory()[1]->content);
        $inMemory->clear();
        $this->assertCount(0, $inMemory->getHistory());

        // SqliteMemory
        $dbPath = $this->tempPath('ultimate_test', 'db');
        @unlink($dbPath);
        $sqlite = new SqliteMemory($dbPath, 'conv-test');
        $sqlite->append(InternalMessage::user('sqlite-test'));
        $sqlite->append(InternalMessage::assistant('sqlite-response'));
        $sqliteHistory = $sqlite->getHistory();
        $this->assertCount(2, $sqliteHistory);
        $this->assertSame('sqlite-test', $sqliteHistory[0]->content);
        $this->assertSame('sqlite-response', $sqliteHistory[1]->content);

        // Persist: a new instance on the same conversation reads the same history.
        $sqliteSecond = new SqliteMemory($dbPath, 'conv-test');
        $this->assertCount(2, $sqliteSecond->getHistory());
        $sqliteSecond->clear();
        $this->assertCount(0, $sqliteSecond->getHistory());
        @unlink($dbPath);

        // FileMemory
        $filePath = $this->tempPath('ultimate_file_mem', 'json');
        @unlink($filePath);
        $fileMemory = new FileMemory($filePath);
        $fileMemory->append(InternalMessage::user('file-test'));
        $fileMemory->append(InternalMessage::assistant('file-response'));
        $fileHistory = $fileMemory->getHistory();
        $this->assertCount(2, $fileHistory);
        $this->assertSame('file-test', $fileHistory[0]->content);

        // Persist: a new instance reads the same file.
        $fileMemorySecond = new FileMemory($filePath);
        $this->assertCount(2, $fileMemorySecond->getHistory());
        $fileMemorySecond->clear();
        $this->assertFileDoesNotExist($filePath);

        // Integration: SqliteMemory wired into an agent (this is the only network
        // call in this section, so every skip guard above runs before it).
        $agentDbPath = $this->tempPath('ultimate_agent', 'db');
        @unlink($agentDbPath);
        $sqliteAgent = $this->makeLlm()->agent(new AgentConfig(
            provider: 'ollama',
            memory:   new SqliteMemory($agentDbPath, 'session-1'),
        ));

        $agentResponse = $sqliteAgent->run('Say OK');

        $this->assertNotSame('', (string) $agentResponse->content);
        @unlink($agentDbPath);
    }

    // ── GenericSkill (DT-3) ────────────────────────────────────────────────

    public function test_generic_skill_from_array_and_create_then_apply(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $llm = $this->makeLlm();

        // fromArray() on the base Skill must return GenericSkill rather than throw
        // "cannot instantiate abstract".
        $skill = Skill::fromArray([
            'name'          => 'concise',
            'system_prompt' => 'You are extremely concise. Reply in 5 words max.',
            'tools'         => [],
        ]);

        $this->assertInstanceOf(GenericSkill::class, $skill);
        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertSame('concise', $skill->getName());

        $createdSkill = Skill::create(
            name:         'pirate',
            systemPrompt: 'You are a pirate. Speak like one.',
        );

        $this->assertInstanceOf(GenericSkill::class, $createdSkill);
        $this->assertSame('pirate', $createdSkill->getName());

        // Use a skill with an agent.
        $llm->skills()->register($skill);

        $skillAgent = $llm->agent(new AgentConfig(
            provider:   'ollama',
            skillNames: ['concise'],
        ));

        $skillResponse = $skillAgent->run('What is PHP?');

        $this->assertNotSame('', (string) $skillResponse->content);
    }

    // ── Config mergedWith() nullable sentinel (DT-2) ───────────────────────

    public function test_config_merged_with_overrides_and_nullable_provider_config(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $base = Config::fromArray(['timeout' => 30, 'retries' => 0]);
        $override = Config::fromArray(['timeout' => 60, 'retries' => 3]);
        $merged = $base->mergedWith($override);

        $this->assertSame(60, $merged->timeout());
        $this->assertSame(3, $merged->retries());

        // ProviderConfig nullable sentinel.
        $providerConfig = new ProviderConfig(
            apiKey: 'key',
            defaultModel: 'gpt-4o',
            timeout: null,
            retries: null,
        );

        $this->assertNull($providerConfig->timeout);
        $this->assertNull($providerConfig->retries);
    }

    // ── HttpClient retry config (DT-1) ─────────────────────────────────────

    public function test_http_client_retry_config_flows_from_bootstrap(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $config = $this->defaultConfig();

        $this->assertSame(1, $config->retries());
        $this->assertSame(200, $config->retryDelayMs());
    }

    // ── Sequential Orchestration ───────────────────────────────────────────

    public function test_sequential_orchestration_dispatches_to_every_agent(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $llm = $this->makeLlm();

        $orchestrator = new Orchestrator(
            routing:       RoutingStrategy::Sequential,
            errorStrategy: ErrorStrategy::Continue,
        );

        $orchestrator->addAgent('step-a', $llm->agent(new AgentConfig(
            provider:     'ollama',
            systemPrompt: 'Reply ONLY with the word "FIRST".',
        )));

        $orchestrator->addAgent('step-b', $llm->agent(new AgentConfig(
            provider:     'ollama',
            systemPrompt: 'Reply ONLY with the word "SECOND".',
        )));

        $result = $orchestrator->dispatch('Say your word.');

        $this->assertCount(2, $result->outcomes);
        $this->assertSame(['step-a', 'step-b'], array_map(
            fn ($outcome) => $outcome->agentName,
            $result->outcomes,
        ));

        // Response wording per agent is model output, so only presence of a
        // response object is checked, never its text.
        foreach ($result->outcomes as $outcome) {
            $this->assertNotNull($outcome->response);
        }
    }

    // ── models() ───────────────────────────────────────────────────────────

    public function test_models_list_returns_an_array(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $models = $this->makeLlm()->models('ollama');

        $this->assertIsArray($models);
    }
}
