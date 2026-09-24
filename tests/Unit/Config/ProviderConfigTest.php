<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Config;

use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Tests\TestCase;
use ReflectionClass;


/**
 * Pins the {@see ProviderConfig} contract: which fields have defaults, how the
 * nullable numeric fields behave as "not overridden", and the env expansion that
 * runs inside the value object rather than in Config::get().
 */
final class ProviderConfigTest extends TestCase
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

    // ── Defaults ────────────────────────────────────────────────────────────

    public function test_direct_construction_requires_only_api_key_and_default_model(): void
    {
        $provider = new ProviderConfig(apiKey: null, defaultModel: 'gpt-4o');

        $this->assertNull($provider->baseUrl);
        $this->assertNull($provider->timeout);
        $this->assertNull($provider->retries);
        $this->assertNull($provider->retryDelayMs);
        $this->assertSame([], $provider->extraHeaders);
    }

    public function test_nullable_numeric_fields_default_to_null_meaning_not_overridden(): void
    {
        $provider = new ProviderConfig(apiKey: 'k', defaultModel: 'gpt-4o');

        // null is the "not overridden" sentinel — the global Config value wins.
        $this->assertNull($provider->timeout);
        $this->assertNull($provider->retries);
        $this->assertNull($provider->retryDelayMs);
    }

    public function test_from_array_falls_back_to_default_model_when_key_is_absent(): void
    {
        $config = Config::fromArray(['providers' => ['openai' => []]]);

        $this->assertSame('default', $config->provider('openai')->defaultModel);
    }

    public function test_from_array_leaves_absent_optional_fields_at_their_defaults(): void
    {
        $config = Config::fromArray([
            'providers' => ['openai' => ['api_key' => 'k', 'default_model' => 'gpt-4o']],
        ]);

        $provider = $config->provider('openai');

        $this->assertNull($provider->baseUrl);
        $this->assertSame([], $provider->extraHeaders);
        $this->assertNull($provider->timeout);
        $this->assertNull($provider->retries);
        $this->assertNull($provider->retryDelayMs);
    }

    // ── Explicit values ─────────────────────────────────────────────────────

    public function test_explicit_values_survive_construction_unchanged(): void
    {
        $provider = new ProviderConfig(
            apiKey: 'sk-explicit',
            defaultModel: 'gpt-4o-mini',
            baseUrl: 'https://proxy.internal/v1',
            timeout: 45,
            retries: 3,
            extraHeaders: ['X-Tenant' => 'acme'],
            retryDelayMs: 250,
        );

        $this->assertSame('sk-explicit', $provider->apiKey);
        $this->assertSame('gpt-4o-mini', $provider->defaultModel);
        $this->assertSame('https://proxy.internal/v1', $provider->baseUrl);
        $this->assertSame(45, $provider->timeout);
        $this->assertSame(3, $provider->retries);
        $this->assertSame(['X-Tenant' => 'acme'], $provider->extraHeaders);
        $this->assertSame(250, $provider->retryDelayMs);
    }

    public function test_from_array_casts_numeric_strings_to_int(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key'        => 'k',
                    'default_model'  => 'gpt-4o',
                    'timeout'        => '90',
                    'retries'        => '2',
                    'retry_delay_ms' => '100',
                ],
            ],
        ]);

        $provider = $config->provider('openai');

        $this->assertSame(90, $provider->timeout);
        $this->assertSame(2, $provider->retries);
        $this->assertSame(100, $provider->retryDelayMs);
    }

    public function test_extra_headers_preserve_their_keys(): void
    {
        $headers = ['X-First' => 'a', 'X-Second' => 'b', 'X-Third' => 'c'];

        $provider = new ProviderConfig(apiKey: 'k', defaultModel: 'm', extraHeaders: $headers);

        $this->assertSame($headers, $provider->extraHeaders);
        $this->assertSame(['X-First', 'X-Second', 'X-Third'], array_keys($provider->extraHeaders));
    }

    // ── Env expansion inside the value object ───────────────────────────────
    //
    // Providers read these properties directly and never pass through
    // Config::get(), so expansion must happen here. Regression guard for the
    // '${OPENAI_API_KEY}' bug where the literal placeholder reached the provider.

    public function test_api_key_expands_env_placeholder_on_construction(): void
    {
        $_ENV['MACRO_LLM_PC_KEY'] = 'sk-from-env';

        $provider = new ProviderConfig(apiKey: '${MACRO_LLM_PC_KEY}', defaultModel: 'gpt-4o');

        $this->assertSame('sk-from-env', $provider->apiKey);
    }

    public function test_default_model_expands_env_placeholder_on_construction(): void
    {
        $_ENV['MACRO_LLM_PC_MODEL'] = 'gpt-4o-2024-11-20';

        $provider = new ProviderConfig(apiKey: 'k', defaultModel: '${MACRO_LLM_PC_MODEL}');

        $this->assertSame('gpt-4o-2024-11-20', $provider->defaultModel);
    }

    public function test_base_url_expands_env_placeholder_on_construction(): void
    {
        $_ENV['MACRO_LLM_PC_HOST'] = 'proxy.internal';

        $provider = new ProviderConfig(
            apiKey: 'k',
            defaultModel: 'm',
            baseUrl: 'https://${MACRO_LLM_PC_HOST}/v1',
        );

        $this->assertSame('https://proxy.internal/v1', $provider->baseUrl);
    }

    public function test_extra_headers_expand_env_placeholders_in_every_value(): void
    {
        $_ENV['MACRO_LLM_PC_TENANT'] = 'acme';
        $_ENV['MACRO_LLM_PC_TRACE']  = 'trace-id';

        $provider = new ProviderConfig(
            apiKey: 'k',
            defaultModel: 'm',
            extraHeaders: [
                'X-Tenant' => '${MACRO_LLM_PC_TENANT}',
                'X-Trace'  => '${MACRO_LLM_PC_TRACE}',
                'X-Static' => 'unchanged',
            ],
        );

        $this->assertSame(
            ['X-Tenant' => 'acme', 'X-Trace' => 'trace-id', 'X-Static' => 'unchanged'],
            $provider->extraHeaders,
        );
    }

    public function test_null_api_key_stays_null_instead_of_becoming_empty_string(): void
    {
        $provider = new ProviderConfig(apiKey: null, defaultModel: 'm');

        $this->assertNull($provider->apiKey);
    }

    public function test_undefined_placeholder_is_left_verbatim(): void
    {
        $provider = new ProviderConfig(
            apiKey: '${MACRO_LLM_PC_DEFINITELY_UNSET}',
            defaultModel: 'm',
        );

        // A misconfigured value stays visible instead of collapsing to ''.
        $this->assertSame('${MACRO_LLM_PC_DEFINITELY_UNSET}', $provider->apiKey);
    }

    // ── Immutability ────────────────────────────────────────────────────────

    public function test_every_property_is_readonly(): void
    {
        $reflection = new ReflectionClass(ProviderConfig::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('ProviderConfig::$%s must be readonly.', $property->getName()),
            );
        }

        $this->assertCount(8, $reflection->getProperties());
    }

    // ── Documented boundary ─────────────────────────────────────────────────

    /**
     * Non-string extra header values are NOT rejected — the closure inside
     * {@see \MacroLLM\Config\EnvResolver::resolveMap()} is invoked by array_map(),
     * and that invocation coerces scalars instead of applying the callee's
     * strict_types declaration. A numeric header value therefore reaches the
     * provider as a string, and a bool reaches it as '1' or ''.
     */
    public function test_non_string_extra_header_values_are_coerced_to_string(): void
    {
        /** @phpstan-ignore-next-line intentional contract boundary */
        $provider = new ProviderConfig(
            apiKey: 'k',
            defaultModel: 'm',
            extraHeaders: ['X-Count' => 42, 'X-Flag' => true],
        );

        $this->assertSame(['X-Count' => '42', 'X-Flag' => '1'], $provider->extraHeaders);
    }
}
