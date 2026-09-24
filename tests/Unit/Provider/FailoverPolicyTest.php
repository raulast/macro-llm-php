<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Provider;

use MacroLLM\Config\Config;
use MacroLLM\Config\ProviderConfig;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Exception\SchemaException;
use MacroLLM\Exception\StructuredOutputUnsupportedException;
use MacroLLM\Exception\UnregisteredProviderException;
use MacroLLM\Provider\FailoverPolicy;
use MacroLLM\Schema\SchemaDialect;
use MacroLLM\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which failures justify trying a DIFFERENT provider.
 *
 * The positive cases are the easy half. The exclusions are the point: a hop spends the next provider's key, so a
 * failure the next provider will reproduce identically must never trigger one.
 */
final class FailoverPolicyTest extends TestCase
{
    /** @return array<string, array{int, bool}> */
    public static function statusProvider(): array
    {
        return [
            'rate limited' => [429, true],
            'internal server error' => [500, true],
            'bad gateway' => [502, true],
            'unavailable' => [503, true],
            'gateway timeout' => [504, true],
            'bad request' => [400, false],
            'unauthorized' => [401, false],
            'forbidden' => [403, false],
            'not found' => [404, false],
            'conflict' => [409, false],
            'unprocessable' => [422, false],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_http_failures_are_classified_by_status(int $status, bool $expected): void
    {
        $failure = ProviderRequestException::forEndpoint('https://example.test', $status, 'body');

        $this->assertSame($expected, FailoverPolicy::isFailoverable($failure));
    }

    public function test_a_connection_failure_is_failoverable(): void
    {
        $this->assertTrue(
            FailoverPolicy::isFailoverable(new \RuntimeException('Connection failed: Connection refused')),
        );
    }

    /**
     * The exclusion that matters most. A 401 is a configuration error the next provider will repeat word for word,
     * so hopping would turn a clear mistake into a confusing chain of failures — and, with a paid provider, into
     * real money spent on the wrong account.
     */
    public function test_a_configuration_failure_never_hops(): void
    {
        $this->assertFalse(FailoverPolicy::isFailoverable(new MissingApiKeyException('openai')));
        $this->assertFalse(FailoverPolicy::isFailoverable(new UnregisteredProviderException('nope')));
    }

    public function test_a_schema_or_capability_problem_is_not_a_transport_problem(): void
    {
        $this->assertFalse(FailoverPolicy::isFailoverable(
            SchemaException::rootNotObject(SchemaDialect::OpenAi, 'array'),
        ));
        $this->assertFalse(FailoverPolicy::isFailoverable(
            StructuredOutputUnsupportedException::noSuchMode('anthropic', 'json_object', 'no such mode'),
        ));
    }

    public function test_the_reason_says_why(): void
    {
        $this->assertSame(
            'rate_limited',
            FailoverPolicy::reason(ProviderRequestException::forEndpoint('https://example.test', 429, '')),
        );
        $this->assertSame(
            'server_error',
            FailoverPolicy::reason(ProviderRequestException::forEndpoint('https://example.test', 503, '')),
        );
        $this->assertSame('connection_failed', FailoverPolicy::reason(new \RuntimeException('boom')));
        $this->assertSame('not_failoverable', FailoverPolicy::reason(new MissingApiKeyException('openai')));

        // A non-failoverable HTTP status is reported as such rather than as a server error.
        $this->assertSame(
            'not_failoverable',
            FailoverPolicy::reason(ProviderRequestException::forEndpoint('https://example.test', 401, '')),
        );
    }

    // ── The configured chain ────────────────────────────────────────────────

    public function test_a_provider_config_carries_no_fallback_by_default(): void
    {
        $this->assertSame([], (new ProviderConfig(apiKey: 'k', defaultModel: 'm'))->fallback);
    }

    public function test_a_provider_config_carries_the_declared_chain(): void
    {
        $config = new ProviderConfig(apiKey: 'k', defaultModel: 'm', fallback: ['anthropic', 'gemini']);

        $this->assertSame(['anthropic', 'gemini'], $config->fallback);
    }

    public function test_config_reads_the_fallback_chain_from_the_array(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key' => 'k',
                    'default_model' => 'gpt-4o',
                    'fallback' => ['anthropic', 'gemini'],
                ],
            ],
        ]);

        $this->assertSame(['anthropic', 'gemini'], $config->provider('openai')?->fallback);
    }

    /** A malformed entry is dropped rather than travelling as a non-string into provider resolution. */
    public function test_config_ignores_non_string_fallback_entries(): void
    {
        $config = Config::fromArray([
            'providers' => [
                'openai' => [
                    'api_key' => 'k',
                    'default_model' => 'gpt-4o',
                    'fallback' => ['anthropic', 42, null, 'gemini'],
                ],
            ],
        ]);

        $this->assertSame(['anthropic', 'gemini'], $config->provider('openai')?->fallback);
    }
}
