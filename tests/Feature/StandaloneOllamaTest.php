<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Feature;

use MacroLLM\Config\Config;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Tests\Concerns\RequiresLiveTests;
use MacroLLM\Tests\Concerns\RequiresOllama;
use MacroLLM\Tests\Concerns\SkipsWithoutApiKey;
use MacroLLM\Tests\TestCase;

/**
 * Standalone facade chat() against a locally running Ollama instance.
 *
 * Ported from the scratch script .idea/tmp.php.
 *
 * Run with:
 *   MACRO_LLM_LIVE_TESTS=1 composer test:feature
 *
 * Without MACRO_LLM_LIVE_TESTS=1 this test skips, so `composer test:feature` stays
 * free of network calls by default.
 */
final class StandaloneOllamaTest extends TestCase
{
    use RequiresLiveTests;
    use RequiresOllama;
    use SkipsWithoutApiKey;

    // ── MacroLLM::standalone() with the ollama provider ─────────────────────

    public function test_standalone_chat_returns_non_empty_content(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        // Deviation from the source script: it used 'minimax-m3:cloud', a cloud
        // model that requires an ollama.com sign-in on the local daemon. The model
        // 'qwen3:1.7b' is verified installed and served locally by this machine's
        // Ollama, so it is used instead.
        $llm = MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'ollama',
            'providers' => [
                'ollama' => [
                    'api_key' => 'ollama-local-apikey',
                    'default_model' => 'qwen3:1.7b',
                ],
            ],
        ]));

        $response = $llm->chat(new InternalRequest([
            InternalMessage::user('Hola quetal ?'),
        ]));

        $this->assertNotNull($response->content);
        $this->assertNotSame('', $response->content);
    }
}
