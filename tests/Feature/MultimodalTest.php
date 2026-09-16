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
 * Multimodal chat() with an inline base64 image, ported from the scratch script
 * .idea/multimodal.tmp.php.
 *
 * Run with:
 *   MACRO_LLM_LIVE_TESTS=1 GEMINI_API_KEY=... composer test:feature
 *
 * Both variables are required: the ported config declares the gemini provider
 * with the '${GEMINI_API_KEY}' placeholder, and the standalone ollama client
 * is checked by probing localhost:11434. Without them this test skips, so
 * `composer test:feature` stays free of network calls by default.
 */
final class MultimodalTest extends TestCase
{
    use RequiresLiveTests;
    use RequiresOllama;
    use SkipsWithoutApiKey;

    // ── userWithImage() with an inline base64 payload ──────────────────────────

    public function test_chat_with_base64_image_returns_non_empty_content(): void
    {
        // Both guards run before any other work in this test.
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GEMINI_API_KEY');
        $this->requireOllama();

        // Deviation from the source script: it chose the provider with
        // `$argv[1] === 'gemini'`. A PHPUnit run has no such argument, so this
        // port keeps the script's no-argument default provider (ollama) while
        // keeping both provider blocks exactly as the script declares them —
        // which is also why GEMINI_API_KEY is guarded above.
        $llm = MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'ollama',
            'providers' => [
                'ollama' => [
                    'api_key' => 'ollama-local',
                    'default_model' => 'gemma4:31b-cloud',  // vision-capable
                ],
                'gemini' => [
                    'api_key' => '${GEMINI_API_KEY}',
                    'default_model' => 'gemini-3-flash-preview',
                ],
            ],
        ]));

        // Image source: the script supplies the image as an inline base64 string
        // (its "Test 2"), so it is ported as-is. Nothing machine-specific and no
        // binary fixture is involved; tests/Fixtures/ holds no image fixture.
        // This is a 1x1 red pixel PNG.
        $redPixelBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI6QAAAABJRU5ErkJggg==';

        $response = $llm->chat(new InternalRequest([
            InternalMessage::userWithImage(
                'What color is this image? One word answer.',
                $redPixelBase64,
                'image/png',
            ),
        ]));

        // The empty-string check matters: a null-only assertion would pass on an
        // empty content and hide a broken response.
        $this->assertNotNull($response->content);
        $this->assertNotSame('', $response->content);
    }
}
