<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Concerns;

/**
 * Master opt-in gate for tests that reach a live provider.
 *
 * The Feature suite is skipped by default, and a per-provider credential check alone is
 * NOT enough to keep it offline: credentials are commonly exported in a developer shell,
 * and {@see SkipsWithoutApiKey} reads them through getenv(). With a key present, a
 * credential check silently turns an ordinary test run into paid API traffic.
 *
 * This gate is therefore checked FIRST, before any credential check. Set
 * MACRO_LLM_LIVE_TESTS=1 to opt the live Feature suite in deliberately.
 */
trait RequiresLiveTests
{
    /** Environment variable that opts the live Feature suite in. */
    public const LIVE_TESTS_ENV = 'MACRO_LLM_LIVE_TESTS';

    /** Values accepted as "enabled", compared case-insensitively. */
    private const TRUTHY = ['1', 'true', 'yes', 'on'];

    protected function skipUnlessLiveTestsEnabled(): void
    {
        $value = getenv(self::LIVE_TESTS_ENV);

        if ($value === false || ! in_array(strtolower(trim($value)), self::TRUTHY, true)) {
            $this->markTestSkipped(sprintf(
                '%s is not set — live tests are opt-in. Run with %s=1 composer test:feature to enable them.',
                self::LIVE_TESTS_ENV,
                self::LIVE_TESTS_ENV,
            ));
        }
    }
}
