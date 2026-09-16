<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;

use MacroLLM\Exception\ContainerBindingException;
use MacroLLM\Exception\MacroLLMException;
use MacroLLM\Exception\MaxToolIterationsException;
use MacroLLM\Exception\MCPConnectionException;
use MacroLLM\Exception\MCPToolCallException;
use MacroLLM\Exception\MissingApiKeyException;
use MacroLLM\Exception\ProviderRequestException;
use MacroLLM\Exception\SkillToolConflictException;
use MacroLLM\Exception\SkillToolNotFoundException;
use MacroLLM\Exception\StreamInterruptedException;
use MacroLLM\Exception\ToolNotFoundException;
use MacroLLM\Exception\UnregisteredProviderException;
use MacroLLM\Message\FinishReason;
use MacroLLM\Message\InternalResponse;
use MacroLLM\Message\StreamChunk;
use MacroLLM\Message\Usage;
use MacroLLM\Tests\TestCase;

/**
 * Characterization tests for all exception value objects in src/Exception/.
 *
 * CRITICAL: MacroLLMException extends \RuntimeException.
 * This inheritance chain is load-bearing: it is exactly what enabled the
 * withRetry() mechanism to catch ALL package exceptions via a single
 * `catch (MacroLLMException)` — if any exception stopped extending
 * MacroLLMException, retry logic would silently swallow unrelated errors.
 * We pin this inheritance in every test case.
 */
final class ExceptionTest extends TestCase
{
    // ── Inheritance chain ───────────────────────────────────────────────────

    #[DataProvider('exceptionInstanceProvider')]
    public function test_every_exception_is_a_macro_llm_exception(\Exception $e): void
    {
        $this->assertInstanceOf(MacroLLMException::class, $e);
    }

    #[DataProvider('exceptionInstanceProvider')]
    public function test_every_exception_extends_runtime_exception(\Exception $e): void
    {
        // MacroLLMException itself extends \RuntimeException.
        // This is the load-bearing chain described in the class docblock.
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    /**
     * Provides one instance of each exception class for the shared assertions above.
     *
     * @return array<string, array{\Exception}>
     */
    public static function exceptionInstanceProvider(): array
    {
        $dummyResponse = new InternalResponse(
            content: 'partial',
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 5, 15),
        );
        $dummyChunk = new StreamChunk(delta: 'hello', index: 0, finished: false);

        return [
            'UnregisteredProviderException'  => [new UnregisteredProviderException('gpt-99')],
            'ToolNotFoundException'           => [new ToolNotFoundException('get_weather')],
            'MissingApiKeyException'          => [new MissingApiKeyException('openai')],
            'ProviderRequestException'        => [new ProviderRequestException('openai', 429, '{"error":"rate_limit"}')],
            'MaxToolIterationsException'      => [new MaxToolIterationsException(10, $dummyResponse)],
            'StreamInterruptedException'      => [new StreamInterruptedException([$dummyChunk])],
            'SkillToolNotFoundException'      => [new SkillToolNotFoundException('translator', 'detect_language')],
            'SkillToolConflictException'      => [new SkillToolConflictException('my_tool', 'skill-a', 'skill-b')],
            'MCPConnectionException'          => [new MCPConnectionException('http://localhost:3001', 'timeout')],
            'MCPToolCallException'            => [new MCPToolCallException('read_file', -32601, 'Method not found')],
            'ContainerBindingException'       => [new ContainerBindingException('SlimContainer')],
        ];
    }

    // ── MacroLLMException base ───────────────────────────────────────────────

    public function test_macro_llm_exception_is_abstract_and_extends_runtime_exception(): void
    {
        $reflection = new \ReflectionClass(MacroLLMException::class);
        $this->assertTrue($reflection->isAbstract(), 'MacroLLMException must be abstract');
        $this->assertSame(\RuntimeException::class, $reflection->getParentClass()->getName());
    }

    // ── Per-exception property assertions ───────────────────────────────────

    public function test_unregistered_provider_exception_exposes_provider_name(): void
    {
        $e = new UnregisteredProviderException('fancy-provider');

        $this->assertSame('fancy-provider', $e->providerName);
        $this->assertStringContainsString('fancy-provider', $e->getMessage());
    }

    public function test_tool_not_found_exception_exposes_tool_name(): void
    {
        $e = new ToolNotFoundException('search_web');

        $this->assertSame('search_web', $e->toolName);
        $this->assertStringContainsString('search_web', $e->getMessage());
    }

    public function test_missing_api_key_exception_exposes_provider_name(): void
    {
        $e = new MissingApiKeyException('anthropic');

        $this->assertSame('anthropic', $e->providerName);
        $this->assertStringContainsString('anthropic', $e->getMessage());
    }

    public function test_provider_request_exception_exposes_all_fields(): void
    {
        $body = '{"error":{"type":"invalid_request_error"}}';
        $e = new ProviderRequestException('gemini', 400, $body);

        $this->assertSame('gemini', $e->providerName);
        $this->assertSame(400, $e->statusCode);
        $this->assertSame($body, $e->responseBody);
        // The HTTP status is also passed as the exception code
        $this->assertSame(400, $e->getCode());
        $this->assertStringContainsString('400', $e->getMessage());
        $this->assertStringContainsString('gemini', $e->getMessage());
    }

    public function test_max_tool_iterations_exception_exposes_count_and_last_response(): void
    {
        $response = new InternalResponse(
            content: 'partial answer',
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(50, 20, 70),
        );
        $e = new MaxToolIterationsException(7, $response);

        $this->assertSame(7, $e->iterations);
        $this->assertSame($response, $e->lastResponse);
        $this->assertStringContainsString('7', $e->getMessage());
    }

    public function test_stream_interrupted_exception_exposes_chunks_and_counts_them(): void
    {
        $chunks = [
            new StreamChunk(delta: 'Hello', index: 0),
            new StreamChunk(delta: ' world', index: 1),
        ];
        $e = new StreamInterruptedException($chunks);

        $this->assertSame($chunks, $e->chunks);
        // Message must mention the chunk count
        $this->assertStringContainsString('2', $e->getMessage());
    }

    public function test_stream_interrupted_exception_with_zero_chunks(): void
    {
        $e = new StreamInterruptedException([]);

        $this->assertSame([], $e->chunks);
        $this->assertStringContainsString('0', $e->getMessage());
    }

    public function test_skill_tool_not_found_exception_exposes_skill_and_tool_name(): void
    {
        $e = new SkillToolNotFoundException('support', 'escalate_ticket');

        $this->assertSame('support', $e->skillName);
        $this->assertSame('escalate_ticket', $e->toolName);
        $this->assertStringContainsString('support', $e->getMessage());
        $this->assertStringContainsString('escalate_ticket', $e->getMessage());
    }

    public function test_skill_tool_conflict_exception_exposes_tool_and_both_skills(): void
    {
        $e = new SkillToolConflictException('search', 'skill-1', 'skill-2');

        $this->assertSame('search', $e->toolName);
        $this->assertSame('skill-1', $e->skillA);
        $this->assertSame('skill-2', $e->skillB);
        $this->assertStringContainsString('search', $e->getMessage());
        $this->assertStringContainsString('skill-1', $e->getMessage());
        $this->assertStringContainsString('skill-2', $e->getMessage());
    }

    public function test_mcp_connection_exception_exposes_url_and_detail(): void
    {
        $e = new MCPConnectionException('http://mcp.example.com', 'connection refused');

        $this->assertSame('http://mcp.example.com', $e->url);
        $this->assertSame('connection refused', $e->detail);
        $this->assertStringContainsString('http://mcp.example.com', $e->getMessage());
        $this->assertStringContainsString('connection refused', $e->detail);
    }

    public function test_mcp_tool_call_exception_exposes_all_fields_and_error_code(): void
    {
        $e = new MCPToolCallException('write_file', -32600, 'Invalid Request');

        $this->assertSame('write_file', $e->toolName);
        $this->assertSame(-32600, $e->errorCode);
        $this->assertSame('Invalid Request', $e->errorMessage);
        // Error code is also forwarded to the exception code
        $this->assertSame(-32600, $e->getCode());
        $this->assertStringContainsString('write_file', $e->getMessage());
        $this->assertStringContainsString('-32600', $e->getMessage());
    }

    public function test_container_binding_exception_exposes_container_class(): void
    {
        $e = new ContainerBindingException('Illuminate\\Container\\Container');

        $this->assertSame('Illuminate\\Container\\Container', $e->containerClass);
        $this->assertStringContainsString('Illuminate\\Container\\Container', $e->getMessage());
    }
}
