<?php

declare(strict_types=1);

namespace MacroLLM\VectorStore;

use MacroLLM\Contract\VectorStoreInterface;
use MacroLLM\Exception\VectorStoreException;

/**
 * A store that keeps vectors in memory and compares them with cosine similarity.
 *
 * **Why this is the driver the package ships.** Retrieval has three honest options and only one of them works
 * everywhere: `sqlite-vec` and `pgvector` both need an extension a `composer require` cannot install, and a library
 * whose selling point is that it runs in any PHP 8.1+ application cannot make either of them the default. This driver
 * needs nothing, and it is the reference implementation the interface is defined against.
 *
 * **What it costs, stated rather than discovered later:** everything is lost when the process ends, memory grows with
 * every entry, and a search scans every vector — `O(n)` per query. That is fine for a few thousand entries, for tests,
 * and for the demo that proves the retrieval path works end to end. A production index wants an approximate-nearest-
 * neighbour driver behind the same interface.
 *
 * Vectors are stored exactly as given. Nothing is normalised on the way in, because quietly transforming a caller's
 * data is the kind of help that produces surprising scores; cosine similarity divides the lengths out anyway.
 */
final class InMemoryVectorStore implements VectorStoreInterface
{
    /** @var array<string, array{embedding: list<float>, metadata: array<string, mixed>}> */
    private array $entries = [];

    private ?int $dimensions = null;

    public function add(string $id, array $embedding, array $metadata = []): void
    {
        $vector = $this->vector($embedding);

        if ($this->dimensions === null) {
            $this->dimensions = count($vector);
        } elseif (count($vector) !== $this->dimensions) {
            throw VectorStoreException::dimensionMismatch($this->dimensions, count($vector));
        }

        // Replacing an existing id is deliberate: re-indexing a document should not require deleting it first.
        $this->entries[$id] = ['embedding' => $vector, 'metadata' => $metadata];
    }

    public function search(array $query, int $limit = 10, float $minSimilarity = 0.0, array $filter = []): array
    {
        $queryVector = $this->vector($query);

        if ($this->dimensions !== null && count($queryVector) !== $this->dimensions) {
            throw VectorStoreException::dimensionMismatch($this->dimensions, count($queryVector));
        }

        $matches = [];

        foreach ($this->entries as $id => $entry) {
            if (!$this->passesFilter($entry['metadata'], $filter)) {
                continue;
            }

            $score = $this->cosine($queryVector, $entry['embedding']);

            if ($score >= $minSimilarity) {
                $matches[] = new VectorMatch($id, $score, $entry['metadata']);
            }
        }

        // Best first, and a stable tie-break on the id so two equally close entries do not reshuffle between runs.
        usort($matches, static function (VectorMatch $a, VectorMatch $b): int {
            return $b->score <=> $a->score ?: strcmp($a->id, $b->id);
        });

        return array_slice($matches, 0, max(0, $limit));
    }

    public function remove(string $id): void
    {
        unset($this->entries[$id]);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
        // Cleared too, so the store can be re-indexed against a different embedding model afterwards.
        $this->dimensions = null;
    }

    /**
     * Validates one vector and returns it as floats.
     *
     * Each check here exists because the alternative is a wrong ANSWER rather than an error: an empty vector scores
     * against nothing, a zero vector has no direction so its cosine is undefined, and a stray string component makes
     * every score involving that vector meaningless.
     *
     * @param  array<int, float|int>  $embedding
     * @return list<float>
     */
    private function vector(array $embedding): array
    {
        if ($embedding === []) {
            throw VectorStoreException::emptyEmbedding();
        }

        $vector = [];

        foreach (array_values($embedding) as $index => $component) {
            if (!is_int($component) && !is_float($component)) {
                throw VectorStoreException::nonNumericComponent($index, $component);
            }

            $vector[] = (float) $component;
        }

        foreach ($vector as $component) {
            if ($component !== 0.0) {
                return $vector;
            }
        }

        throw VectorStoreException::zeroVector();
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
            $normA += $value * $value;
            $normB += $b[$index] * $b[$index];
        }

        // Both vectors are known non-zero and of equal length, so neither denominator can be zero here.
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param  array<string, mixed>        $metadata
     * @param  array<string, scalar|null>  $filter
     */
    private function passesFilter(array $metadata, array $filter): bool
    {
        foreach ($filter as $key => $expected) {
            if (!array_key_exists($key, $metadata) || $metadata[$key] !== $expected) {
                return false;
            }
        }

        return true;
    }
}
