<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Feature;

use MacroLLM\Config\Config;
use MacroLLM\Contract\AudioProviderInterface;
use MacroLLM\Contract\EmbeddingProviderInterface;
use MacroLLM\Contract\ImageProviderInterface;
use MacroLLM\Contract\ProviderInterface;
use MacroLLM\Contract\RerankingProviderInterface;
use MacroLLM\MacroLLM;
use MacroLLM\Message\AudioRequest;
use MacroLLM\Message\EmbeddingRequest;
use MacroLLM\Message\EmbeddingResponse;
use MacroLLM\Message\TranscriptionRequest;
use MacroLLM\Message\TranscriptionResponse;
use MacroLLM\Tests\Concerns\RequiresLiveTests;
use MacroLLM\Tests\Concerns\RequiresOllama;
use MacroLLM\Tests\Concerns\SkipsWithoutApiKey;
use MacroLLM\Tests\TestCase;

/**
 * Live feature coverage ported from the scratch script .idea/feature01.tmp.php:
 * provider capability detection plus F-5 (embeddings) and F-7 (audio STT/TTS)
 * across the groq, gemini, ollama and elevenlabs providers.
 *
 * Run with:
 *   MACRO_LLM_LIVE_TESTS=1 GEMINI_API_KEY=... GROQ_API_KEY=... \
 *     ELEVENLABS_API_KEY=... composer test:feature
 *
 * Each test is gated on the environment variable its provider needs
 * and MACRO_LLM_LIVE_TESTS=1, so
 * without them `composer test:feature` stays free of network calls and every
 * test in this file reports as skipped.
 *
 * Note for the ollama embeddings test: the daemon needs an embedding model
 * pulled first, e.g. `ollama pull nomic-embed-text`.
 */
final class Feature01Test extends TestCase
{
    use RequiresLiveTests;
    use RequiresOllama;
    use SkipsWithoutApiKey;

    /**
     * Inputs shared by the gemini and ollama F-5 embedding tests.
     *
     * @var list<string>
     */
    private const EMBEDDING_INPUTS = [
        'PHP is a server-side language.',
        'Laravel is a PHP framework.',
        'Python is for data science.',
    ];

    // ── Groq ───────────────────────────────────────────────────────────────

    public function test_groq_provider_exposes_at_least_one_capability_interface(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GROQ_API_KEY');

        $capabilities = $this->supportedCapabilities($this->groqLlm()->providers()->get('groq'));

        $this->assertNotEmpty(
            $capabilities,
            'groq must satisfy at least one of the embedding/image/audio/reranking capability interfaces.',
        );
    }

    public function test_groq_embeddings_are_unavailable_on_the_free_plan(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GROQ_API_KEY');

        $this->markTestSkipped(
            'Groq embeddings (nomic-embed-text-v1-5) are not available on the free plan.',
        );
    }

    public function test_groq_transcription_returns_a_response(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GROQ_API_KEY');
        $this->skipUnlessAudioFixtureExists();

        $response = $this->groqLlm()->transcribe(new TranscriptionRequest(
            filePath: $this->audioFixturePath(),
            model: 'whisper-large-v3-turbo',
        ));

        $this->assertInstanceOf(TranscriptionResponse::class, $response);
        // The script tolerated an empty string here (silence decodes to ''), so
        // the contract is only that the property is a string, never a shape.
        $this->assertIsString($response->text);
    }

    // ── Gemini ─────────────────────────────────────────────────────────────

    public function test_gemini_provider_exposes_at_least_one_capability_interface(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GEMINI_API_KEY');

        $capabilities = $this->supportedCapabilities($this->geminiLlm()->providers()->get('gemini'));

        $this->assertNotEmpty(
            $capabilities,
            'gemini must satisfy at least one of the embedding/image/audio/reranking capability interfaces.',
        );
    }

    public function test_gemini_embeddings_return_one_vector_per_input(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('GEMINI_API_KEY');

        $response = $this->geminiLlm()->embed(new EmbeddingRequest(
            inputs: self::EMBEDDING_INPUTS,
            model: 'gemini-embedding-001',
        ));

        $this->assertSame(3, count($response->embeddings));

        $this->assertEmbeddingsAreUsable($response);
    }

    // ── Ollama ─────────────────────────────────────────────────────────────

    public function test_ollama_provider_exposes_at_least_one_capability_interface(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $capabilities = $this->supportedCapabilities($this->ollamaLlm()->providers()->get('ollama'));

        $this->assertNotEmpty(
            $capabilities,
            'ollama must satisfy at least one of the embedding/image/audio/reranking capability interfaces.',
        );
    }

    public function test_ollama_embeddings_return_one_vector_per_input(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->requireOllama();

        $response = $this->ollamaLlm()->embed(new EmbeddingRequest(
            inputs: self::EMBEDDING_INPUTS,
        ));

        $this->assertSame(3, count($response->embeddings));

        $this->assertEmbeddingsAreUsable($response);
    }

    // ── ElevenLabs ─────────────────────────────────────────────────────────

    public function test_elevenlabs_provider_exposes_at_least_one_capability_interface(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('ELEVENLABS_API_KEY');

        $capabilities = $this->supportedCapabilities($this->elevenLabsLlm()->providers()->get('elevenlabs'));

        $this->assertNotEmpty(
            $capabilities,
            'elevenlabs must satisfy at least one of the embedding/image/audio/reranking capability interfaces.',
        );
    }

    public function test_elevenlabs_synthesizes_audio_content(): void
    {
        $this->skipUnlessLiveTestsEnabled();
        $this->skipUnlessApiKey('ELEVENLABS_API_KEY');

        $response = $this->elevenLabsLlm()->audio(new AudioRequest(
            text: 'Hello from macro-llm-php. ElevenLabs TTS works perfectly.',
            model: 'eleven_multilingual_v2',
        ));

        // The source script stored the payload to /tmp and printed its kilobyte
        // size; writing outside the repository is not allowed here, so the same
        // signal is asserted on the in-memory payload instead.
        $this->assertNotSame('', $response->content);
        $this->assertNotSame('', $response->format);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function groqLlm(): MacroLLM
    {
        return MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'groq',
            'providers' => [
                'groq' => [
                    'api_key' => '${GROQ_API_KEY}',
                    'default_model' => 'llama-3.3-70b-versatile',
                ],
            ],
        ]));
    }

    private function geminiLlm(): MacroLLM
    {
        return MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'gemini',
            'providers' => [
                'gemini' => [
                    'api_key' => '${GEMINI_API_KEY}',
                    'default_model' => 'gemini-3-flash-preview',
                ],
            ],
        ]));
    }

    private function ollamaLlm(): MacroLLM
    {
        return MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'ollama',
            'providers' => [
                'ollama' => [
                    'api_key' => 'local',
                    'default_model' => 'nomic-embed-text',
                ],
            ],
        ]));
    }

    private function elevenLabsLlm(): MacroLLM
    {
        return MacroLLM::standalone(Config::fromArray([
            'default_provider' => 'elevenlabs',
            'providers' => [
                'elevenlabs' => [
                    'api_key' => '${ELEVENLABS_API_KEY}',
                    // Free tier: use a voice from YOUR account (not library voices).
                    // Get your voice_id from: https://elevenlabs.io/app/voice-lab
                    'default_model' => 'UyYGKL24M8JK9sBROwzN',
                ],
            ],
        ]));
    }

    /**
     * Names of the capability interfaces the resolved provider satisfies.
     *
     * @return list<string>
     */
    private function supportedCapabilities(ProviderInterface $provider): array
    {
        $supported = array_filter([
            'embed' => $provider instanceof EmbeddingProviderInterface,
            'image' => $provider instanceof ImageProviderInterface,
            'audio' => $provider instanceof AudioProviderInterface,
            'rerank' => $provider instanceof RerankingProviderInterface,
        ]);

        return array_keys($supported);
    }

    /**
     * Weakest assertions that still prove the embedding payload is usable:
     * one non-empty vector per input, consistent dimensions, and cosine
     * similarities that are real numbers. Exact similarity ordering is not
     * asserted because the source script tolerated either outcome.
     */
    private function assertEmbeddingsAreUsable(EmbeddingResponse $response): void
    {
        $dimensions = count($response->embeddings[0]);

        $this->assertGreaterThan(0, $dimensions, 'Each embedding vector must have at least one dimension.');

        foreach ($response->embeddings as $index => $embedding) {
            $this->assertSame(
                $dimensions,
                count($embedding),
                "Embedding at index {$index} must have the same dimension as the first one.",
            );
        }

        $simPhpLaravel = $this->cosineSimilarity($response->embeddings[0], $response->embeddings[1]);
        $simPhpPython = $this->cosineSimilarity($response->embeddings[0], $response->embeddings[2]);

        $this->assertFalse(is_nan($simPhpLaravel), 'cos(PHP, Laravel) must be a real number.');
        $this->assertFalse(is_nan($simPhpPython), 'cos(PHP, Python) must be a real number.');
        $this->assertGreaterThan(
            -1.0001,
            $simPhpLaravel,
            'cos(PHP, Laravel) must be a valid cosine similarity (>= -1).',
        );
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = array_sum(array_map(static fn ($x, $y) => $x * $y, $a, $b));
        $normA = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $a)));
        $normB = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $b)));

        return $dot / ($normA * $normB);
    }

    /**
     * The STT fixture lives in the gitignored .idea/ directory, so its absence
     * is reported as a skip instead of a failure. Runs before any network call.
     */
    private function skipUnlessAudioFixtureExists(): void
    {
        if (! file_exists($this->audioFixturePath())) {
            $this->markTestSkipped(
                'Voice fixture .idea/voice_miguel.mp3 is not present — skipping live STT test.',
            );
        }
    }

    private function audioFixturePath(): string
    {
        return dirname(__DIR__, 2) . '/.idea/voice_miguel.mp3';
    }
}
