<?php

declare(strict_types=1);

namespace MacroLLM\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for macro-llm-php.
 *
 * Provides helpers for loading provider response fixtures from
 * tests/Fixtures/provider-responses/{provider}/.
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * Load a JSON fixture file and decode it to an array.
     *
     * @param  string  $provider  e.g. 'openai', 'anthropic'
     * @param  string  $name      filename without .json extension, e.g. 'chat-basic'
     * @return array<mixed>
     */
    protected function fixtureJson(string $provider, string $name): array
    {
        $path = __DIR__ . '/Fixtures/provider-responses/' . $provider . '/' . $name . '.json';

        if (! file_exists($path)) {
            $this->fail("Fixture not found: {$path}");
        }

        $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            $this->fail("Fixture is not a JSON object/array: {$path}");
        }

        return $decoded;
    }

    /**
     * Load a plain-text SSE fixture file.
     *
     * @param  string  $provider  e.g. 'openai', 'anthropic'
     * @param  string  $name      filename without .txt extension, e.g. 'sse-delta'
     */
    protected function fixtureSse(string $provider, string $name): string
    {
        $path = __DIR__ . '/Fixtures/provider-responses/' . $provider . '/' . $name . '.txt';

        if (! file_exists($path)) {
            $this->fail("SSE fixture not found: {$path}");
        }

        return file_get_contents($path);
    }
}
