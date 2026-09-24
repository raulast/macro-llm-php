<?php

declare(strict_types=1);

namespace MacroLLM\Tool;

use MacroLLM\Contract\VectorStoreInterface;

/**
 * Turns a vector store into a tool an Agent can call.
 *
 * A store alone does not make RAG expressible: the model has to be able to ASK for the documents, and that means a
 * tool whose arguments it can fill in and whose result it can read. This is that tool, and it deliberately introduces
 * no new concepts — it is a `ToolDefinition` built the same way as any other, offered through the same array, and
 * executed by the same loop.
 *
 * The embedding step is a callable rather than a `MacroLLM` instance, which keeps the tool testable without a provider
 * and usable with an embedding model this package does not drive:
 *
 * ```php
 * $tool = SimilaritySearchTool::using(
 *     store: $store,
 *     embed: fn (string $query): array => $llm->embed(new EmbeddingRequest([$query]), 'openai')->embeddings[0],
 * );
 *
 * $llm->agent(new AgentConfig(tools: [$tool]))->run('What does the handbook say about expenses?');
 * ```
 */
final class SimilaritySearchTool
{
    /**
     * @param  callable(string): array<int, float|int>  $embed           A query string into a vector.
     * @param  string                                   $name            The name the model will call.
     * @param  int                                      $limit           How many documents to hand back at most.
     * @param  float                                    $minSimilarity   Cosine floor; the default drops unrelated
     *                                                                   matches, which is usually what a retrieval
     *                                                                   wants.
     */
    public static function using(
        VectorStoreInterface $store,
        callable $embed,
        string $name = 'search_documents',
        string $description = 'Search the indexed documents for passages relevant to a query.',
        int $limit = 5,
        float $minSimilarity = 0.3,
    ): ToolDefinition {
        return new ToolDefinition(
            name: $name,
            description: $description,
            // Ordinary JSON Schema, so the arguments a model sends are validated against it by the Agent loop before
            // this callable runs — the same guarantee every other tool gets.
            parameters: [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'What to look for, in natural language.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => max(1, $limit),
                        'description' => sprintf('How many documents to return, at most %d.', $limit),
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            callable: static function (array $arguments) use ($store, $embed, $limit, $minSimilarity): array {
                $query = $arguments['query'] ?? '';
                $requested = $arguments['limit'] ?? $limit;

                $matches = $store->search(
                    query: $embed((string) $query),
                    limit: is_int($requested) ? min($requested, $limit) : $limit,
                    minSimilarity: $minSimilarity,
                );

                // An empty result is a legitimate answer, not an error: telling the model "nothing matched" lets it
                // rephrase or conclude, where an exception would end the turn.
                return array_map(static fn ($match): array => $match->toArray(), $matches);
            },
        );
    }
}
