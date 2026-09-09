<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Config;

use MacroLLM\Config\Config;
use MacroLLM\Config\EnvResolver;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Tests\TestCase;

final class ConfigTest extends TestCase
{
    /** @var array<string, string> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    // ── Env placeholder expansion ───────────────────────────────────────────
    //
    // Regression guard. Providers read ProviderConfig properties directly and
    // never go through Config::get(), so expansion must happen in the value
    // object itself. Before this was fixed, '${OPENAI_API_KEY}' reached the
    // provider verbatim and every request failed with a confusing 401.

    public function test_provider_api_key_expands_env_placeholder(): void
    {
        $_ENV['MACRO_LLM_TEST_KEY'] = 'sk-resolved-value';

        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key'       => '${MACRO_LLM_TEST_KEY}',
                    'default_model' => 'gpt-4o',
                ],
            ],
        ]);

        $this->assertSame('sk-resolved-value', $config->provider('openai')->apiKey);
    }

    public function test_provider_base_url_expands_env_placeholder(): void
    {
        $_ENV['MACRO_LLM_TEST_HOST'] = 'proxy.internal';

        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key'       => 'static',
                    'default_model' => 'gpt-4o',
                    'base_url'      => 'https://${MACRO_LLM_TEST_HOST}/v1',
                ],
            ],
        ]);

        $this->assertSame('https://proxy.internal/v1', $config->provider('openai')->baseUrl);
    }

    public function test_provider_extra_headers_expand_env_placeholders(): void
    {
        $_ENV['MACRO_LLM_TEST_TENANT'] = 'tenant-42';

        $config = Config::fromArray([
            'providers' => [
                'azure' => [
                    'api_key'       => 'static',
                    'default_model' => 'gpt-4o',
                    'extra_headers' => ['X-Tenant' => '${MACRO_LLM_TEST_TENANT}'],
                ],
            ],
        ]);

        $this->assertSame(
            ['X-Tenant' => 'tenant-42'],
            $config->provider('azure')->extraHeaders,
        );
    }

    public function test_undefined_placeholder_is_left_verbatim(): void
    {
        unset($_ENV['MACRO_LLM_TEST_ABSENT']);

        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key'       => '${MACRO_LLM_TEST_ABSENT}',
                    'default_model' => 'gpt-4o',
                ],
            ],
        ]);

        // Deliberate: collapsing to an empty string would hide the misconfiguration.
        $this->assertSame(
            '${MACRO_LLM_TEST_ABSENT}',
            $config->provider('openai')->apiKey,
        );
    }

    public function test_value_without_placeholder_is_untouched(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => ['api_key' => 'sk-literal', 'default_model' => 'gpt-4o'],
            ],
        ]);

        $this->assertSame('sk-literal', $config->provider('openai')->apiKey);
    }

    public function test_config_get_also_expands_placeholders(): void
    {
        $_ENV['MACRO_LLM_TEST_KEY'] = 'sk-via-get';

        $config = Config::fromArray([
            'providers' => [
                'openai' => ['api_key' => '${MACRO_LLM_TEST_KEY}', 'default_model' => 'gpt-4o'],
            ],
        ]);

        $this->assertSame('sk-via-get', $config->get('providers.openai.api_key'));
    }

    // ── EnvResolver directly ────────────────────────────────────────────────

    public function test_resolver_expands_multiple_placeholders_in_one_value(): void
    {
        $_ENV['MACRO_LLM_TEST_A'] = 'alpha';
        $_ENV['MACRO_LLM_TEST_B'] = 'beta';

        $this->assertSame(
            'alpha-beta',
            EnvResolver::resolve('${MACRO_LLM_TEST_A}-${MACRO_LLM_TEST_B}'),
        );
    }

    public function test_resolver_preserves_null(): void
    {
        $this->assertNull(EnvResolver::resolveNullable(null));
    }

    // ── Defaults and accessors ──────────────────────────────────────────────

    public function test_global_defaults(): void
    {
        $config = Config::fromArray([]);

        $this->assertSame(30, $config->timeout());
        $this->assertSame(0, $config->retries());
        $this->assertSame(500, $config->retryDelayMs());
        $this->assertSame(10, $config->maxToolIterations());
        $this->assertNull($config->defaultProvider());
    }

    public function test_unknown_provider_returns_null(): void
    {
        $this->assertNull(Config::fromArray([])->provider('nope'));
    }

    // ── Nullable per-provider overrides ─────────────────────────────────────
    //
    // null means "not overridden" so the global value applies. Absent keys must
    // stay null rather than silently adopting a hardcoded default.

    public function test_absent_numeric_overrides_stay_null(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => ['api_key' => 'k', 'default_model' => 'gpt-4o'],
            ],
        ]);

        $provider = $config->provider('openai');

        $this->assertNull($provider->timeout);
        $this->assertNull($provider->retries);
        $this->assertNull($provider->retryDelayMs);
    }

    public function test_present_numeric_overrides_are_cast_to_int(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key'        => 'k',
                    'default_model'  => 'gpt-4o',
                    'timeout'        => '45',
                    'retries'        => '3',
                    'retry_delay_ms' => '250',
                ],
            ],
        ]);

        $provider = $config->provider('openai');

        $this->assertSame(45, $provider->timeout);
        $this->assertSame(3, $provider->retries);
        $this->assertSame(250, $provider->retryDelayMs);
    }

    // ── mergedWith ──────────────────────────────────────────────────────────

    public function test_merged_with_null_returns_same_instance(): void
    {
        $config = Config::fromArray(['timeout' => 30]);

        $this->assertSame($config, $config->mergedWith(null));
    }

    public function test_merged_with_applies_non_default_overrides(): void
    {
        $base   = Config::fromArray(['timeout' => 30, 'retries' => 0]);
        $merged = $base->mergedWith(Config::fromArray(['timeout' => 60, 'retries' => 3]));

        $this->assertSame(60, $merged->timeout());
        $this->assertSame(3, $merged->retries());
    }

    public function test_merged_with_override_default_provider(): void
    {
        $base   = Config::fromArray(['default_provider' => 'openai']);
        $merged = $base->mergedWith(Config::fromArray(['default_provider' => 'anthropic']));

        $this->assertSame('anthropic', $merged->defaultProvider());
    }

    public function test_merged_with_unions_providers(): void
    {
        $base = Config::fromArray([
            'providers' => ['openai' => ['api_key' => 'a', 'default_model' => 'gpt-4o']],
        ]);
        $merged = $base->mergedWith(Config::fromArray([
            'providers' => ['groq' => ['api_key' => 'b', 'default_model' => 'llama']],
        ]));

        $this->assertNotNull($merged->provider('openai'));
        $this->assertNotNull($merged->provider('groq'));
    }

    /**
     * Documents a known limitation rather than endorsing it: mergedWith compares
     * against hardcoded defaults to decide whether a value was set, so an override
     * that happens to equal the default cannot be distinguished from an unset one.
     */
    public function test_merged_with_cannot_force_an_override_equal_to_the_default(): void
    {
        $base   = Config::fromArray(['timeout' => 90]);
        $merged = $base->mergedWith(Config::fromArray(['timeout' => 30]));

        $this->assertSame(90, $merged->timeout());
    }

    // ── Direct instantiation ────────────────────────────────────────────────

    public function test_provider_config_expands_placeholders_when_built_directly(): void
    {
        $_ENV['MACRO_LLM_TEST_KEY'] = 'sk-direct';

        $provider = new ProviderConfig(
            apiKey: '${MACRO_LLM_TEST_KEY}',
            defaultModel: 'gpt-4o',
        );

        $this->assertSame('sk-direct', $provider->apiKey);
    }
}
