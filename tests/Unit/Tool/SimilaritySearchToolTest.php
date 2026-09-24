<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\Tool;

use MacroLLM\Schema\SchemaValidator;
use MacroLLM\Tests\TestCase;
use MacroLLM\Tool\SimilaritySearchTool;
use MacroLLM\VectorStore\InMemoryVectorStore;

/**
 * The tool that makes retrieval reachable from an Agent. Two things matter beyond "it returns matches": the schema it
 * declares is one the package's own validator accepts, and the callable it produces handles the arguments a model
 * actually sends.
 */
final class SimilaritySearchToolTest extends TestCase
{
    /** A store with three documents, and an embedder that maps a query onto one of their directions. */
    private function store(): InMemoryVectorStore
    {
        $store = new InMemoryVectorStore();
        $store->add('cat', [1.0, 0.0], ['text' => 'the cat sat on the mat']);
        $store->add('dog', [0.0, 1.0], ['text' => 'the dog barked']);

        return $store;
    }

    /** @param callable(string): array<int, float>|null $embed */
    private function tool(?callable $embed = null): \MacroLLM\Tool\ToolDefinition
    {
        return SimilaritySearchTool::using(
            store: $this->store(),
            embed: $embed ?? static fn (string $query): array => [1.0, 0.0],
        );
    }

    public function test_the_tool_is_a_tool_definition_like_any_other(): void
    {
        $tool = $this->tool();

        $this->assertSame('search_documents', $tool->name);
        $this->assertNotSame('', $tool->description);
        $this->assertSame(['query'], $tool->parameters['required']);
        $this->assertFalse($tool->parameters['additionalProperties']);
    }

    /**
     * The declared schema is validated by the package's own validator — the same one the Agent runs before a callable
     * — so a tool whose schema it rejects could never be used. This pins that self-consistency.
     */
    public function test_its_declared_schema_passes_the_packages_own_validator(): void
    {
        (new SchemaValidator())->validate(
            ['query' => 'expenses', 'limit' => 3],
            $this->tool()->parameters,
        );

        $this->expectException(\MacroLLM\Exception\SchemaValidationException::class);
        (new SchemaValidator())->validate([], $this->tool()->parameters);
    }

    public function test_calling_it_returns_the_ranked_matches(): void
    {
        $tool = $this->tool();
        $results = ($tool->callable)(['query' => 'feline']);

        $this->assertCount(1, $results, 'the dog is orthogonal to the query vector and below the floor');
        $this->assertSame('cat', $results[0]['id']);
        $this->assertSame('the cat sat on the mat', $results[0]['metadata']['text']);
        $this->assertIsFloat($results[0]['score']);
    }

    public function test_the_query_string_reaches_the_embedder(): void
    {
        $seen = null;
        $tool = $this->tool(static function (string $query) use (&$seen): array {
            $seen = $query;

            return [1.0, 0.0];
        });

        ($tool->callable)(['query' => 'what about expenses?']);

        $this->assertSame('what about expenses?', $seen);
    }

    public function test_a_limit_argument_narrows_the_result_and_cannot_exceed_the_tool_limit(): void
    {
        $store = new InMemoryVectorStore();
        $store->add('a', [1.0, 0.0]);
        $store->add('b', [1.0, 0.0]);
        $store->add('c', [1.0, 0.0]);

        $tool = SimilaritySearchTool::using(
            store: $store,
            embed: static fn (string $query): array => [1.0, 0.0],
            limit: 2,
        );

        $this->assertCount(1, ($tool->callable)(['query' => 'x', 'limit' => 1]));
        // Asking for more than the tool allows is clamped rather than honoured, so a model cannot ask for the whole
        // index by guessing a large number.
        $this->assertCount(2, ($tool->callable)(['query' => 'x', 'limit' => 99]));
        $this->assertCount(2, ($tool->callable)(['query' => 'x']));
    }

    /** No matches is a legitimate answer: the model can rephrase or conclude instead of the turn ending. */
    public function test_no_matches_returns_an_empty_list_rather_than_failing(): void
    {
        // One document, and a query pointing the other way: with two orthogonal documents any vector is close to one
        // of them, so the empty case needs a store where "far from everything" is possible.
        $store = new InMemoryVectorStore();
        $store->add('cat', [1.0, 0.0], ['text' => 'the cat sat on the mat']);

        $tool = SimilaritySearchTool::using(
            store: $store,
            embed: static fn (string $query): array => [-1.0, 0.0],
        );

        $results = ($tool->callable)(['query' => 'nothing like the cat']);

        $this->assertSame([], $results);
    }

    public function test_the_name_and_description_are_configurable(): void
    {
        $tool = SimilaritySearchTool::using(
            store: $this->store(),
            embed: static fn (string $query): array => [1.0, 0.0],
            name: 'lookup',
            description: 'Looks things up in the handbook.',
        );

        $this->assertSame('lookup', $tool->name);
        $this->assertSame('Looks things up in the handbook.', $tool->description);
    }
}
