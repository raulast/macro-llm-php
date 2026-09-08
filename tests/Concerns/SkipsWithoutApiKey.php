<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Concerns;

/**
 * Trait for test cases that require a live API key to run.
 *
 * Use in Feature tests or integration tests that need a real provider.
 */
trait SkipsWithoutApiKey
{
    /**
     * Skip the current test if the given environment variable is not set or empty.
     *
     * @param  string  $envVar  Environment variable name, e.g. 'OPENAI_API_KEY'
     */
    protected function skipUnlessApiKey(string $envVar): void
    {
        $value = getenv($envVar);

        if ($value === false || $value === '') {
            $this->markTestSkipped("{$envVar} is not set — skipping live test.");
        }
    }
}
