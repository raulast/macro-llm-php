<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Feature;

use DI\Container;
use MacroLLM\Integration\Slim\MacroLLMSlimExtension;
use MacroLLM\MacroLLM;
use MacroLLM\Message\InternalMessage;
use MacroLLM\Message\InternalRequest;
use MacroLLM\Tests\Concerns\RequiresLiveTests;
use MacroLLM\Tests\Concerns\RequiresOllama;
use MacroLLM\Tests\Concerns\SkipsWithoutApiKey;
use MacroLLM\Tests\TestCase;

/**
 * MacroLLMSlimExtension registration and resolution inside a PHP-DI container,
 * followed by a chat() call against a locally running Ollama instance.
 *
 * Ported from the scratch script .idea/slim.php.
 *
 * Run with:
 *   MACRO_LLM_LIVE_TESTS=1 composer test:feature
 *
 * Without MACRO_LLM_LIVE_TESTS=1 this test skips, so `composer test:feature` stays
 * free of network calls by default.
 */
final class SlimIntegrationTest extends TestCase
{
    use RequiresLiveTests;
    use RequiresOllama;
    use SkipsWithoutApiKey;

    // ── MacroLLMSlimExtension::register() + container resolution ────────────

    public function test_slim_extension_registers_and_resolves_macro_llm(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        // Dependency guards run before any class is touched or any network call
        // is attempted, so a missing optional package never produces an error.
        if (! class_exists(Container::class)) {
            $this->markTestSkipped('php-di/php-di is not installed');
        }

        if (! class_exists(MacroLLMSlimExtension::class)) {
            $this->markTestSkipped('slim/slim is not installed');
        }

        $container = new Container();

        // Deviation from the source script: it used 'minimax-m3:cloud', a cloud
        // model that requires an ollama.com sign-in on the local daemon. The model
        // 'qwen3:1.7b' is installed and served locally by this machine's Ollama.
        $config = [
            'default_provider' => 'ollama',
            'providers' => [
                'ollama' => [
                    'api_key'       => 'ollama-local-apikey',
                    'default_model' => 'qwen3:1.7b',
                ],
            ],
        ];

        $extension = new MacroLLMSlimExtension($container, $config);
        $extension->register();

        $macroLLM = $container->get(MacroLLM::class);

        $this->assertInstanceOf(MacroLLM::class, $macroLLM);

        $response = $macroLLM->chat(new InternalRequest([
            InternalMessage::user('Hello from Slim!'),
        ]));

        $this->assertNotNull($response->content);
        $this->assertNotSame('', $response->content);
    }
}
