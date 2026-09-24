<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\VectorStore\VectorMatch;

/**
 * Where embeddings live and how they are searched.
 *
 * The package could already embed and rerank; it could not retrieve, which is the one piece RAG is built on. This is
 * that piece, and it is an interface first because **the driver decision belongs to the application, not to the
 * library**: a package that requires a Postgres extension to do retrieval is not the same product as one that works
 * in a PHP script.
 *
 * Implementations MUST rank by descending similarity and MUST reject a vector whose dimension does not match what is
 * already stored. That second rule is not pedantry: silently comparing a 1536-dimension query against 768-dimension
 * entries produces numbers that look like scores and mean nothing.
 */
interface VectorStoreInterface
{
    /**
     * Store one embedding. An existing id is REPLACED, so a store can be re-indexed without clearing it first.
     *
     * @param  array<int, float|int>  $embedding
     * @param  array<string, mixed>   $metadata   Returned with any match, for filtering and for context.
     */
    public function add(string $id, array $embedding, array $metadata = []): void;

    /**
     * The closest entries to a query vector, best first.
     *
     * @param  array<int, float|int>       $query
     * @param  int                         $limit          How many matches to return at most.
     * @param  float                       $minSimilarity  Cosine similarity floor; lower-scoring entries are dropped.
     * @param  array<string, scalar|null>  $filter         Exact-match metadata constraints, ANDed together.
     * @return list<VectorMatch>
     */
    public function search(array $query, int $limit = 10, float $minSimilarity = 0.0, array $filter = []): array;

    /** Removing an id that is not there is a no-op, so a re-index does not have to know what it is deleting. */
    public function remove(string $id): void;

    public function count(): int;

    public function clear(): void;
}
