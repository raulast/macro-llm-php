<?php

declare(strict_types=1);

namespace MacroLLM\Tests\Unit\VectorStore;

use MacroLLM\Exception\VectorStoreException;
use MacroLLM\VectorStore\InMemoryVectorStore;
use MacroLLM\VectorStore\VectorMatch;
use MacroLLM\Tests\TestCase;

/**
 * The reference vector store. What matters is that a retrieval answers in the right ORDER, that the guards exist
 * because the alternative is a wrong answer rather than an error, and that the thresholds and filters mean what they
 * say.
 */
final class InMemoryVectorStoreTest extends TestCase
{
    private function store(): InMemoryVectorStore
    {
        return new InMemoryVectorStore();
    }

    // ── Retrieval ───────────────────────────────────────────────────────────

    public function test_the_closest_entries_come_back_first(): void
    {
        $store = $this->store();
        $store->add('near', [1.0, 0.0, 0.0]);
        $store->add('middle', [0.7, 0.7, 0.0]);
        $store->add('far', [0.0, 0.0, 1.0]);

        $matches = $store->search([1.0, 0.0, 0.0]);

        $this->assertSame(['near', 'middle', 'far'], array_map(fn (VectorMatch $m): string => $m->id, $matches));
    }

    public function test_an_identical_vector_scores_one_and_an_opposite_one_scores_minus_one(): void
    {
        $store = $this->store();
        $store->add('same', [1.0, 2.0, 3.0]);
        $store->add('opposite', [-1.0, -2.0, -3.0]);

        // The floor has to be lowered explicitly: the default is 0.0, so a NEGATIVE similarity is dropped as a weak
        // match. That is the right default — an opposite vector is not a retrieval — and it has to be opted out of.
        $matches = $store->search([1.0, 2.0, 3.0], minSimilarity: -1.0);

        $this->assertEqualsWithDelta(1.0, $matches[0]->score, 1e-9);
        $this->assertEqualsWithDelta(-1.0, $matches[1]->score, 1e-9);
    }

    public function test_the_default_floor_drops_an_opposite_vector(): void
    {
        $store = $this->store();
        $store->add('opposite', [-1.0, -2.0, -3.0]);

        $this->assertSame([], $store->search([1.0, 2.0, 3.0]));
    }

    /** Length must not matter: cosine divides the magnitudes out, so a longer vector of the same direction is a match. */
    public function test_magnitude_does_not_change_the_ranking(): void
    {
        $store = $this->store();
        $store->add('short', [1.0, 0.0]);
        $store->add('long', [100.0, 0.0]);

        $matches = $store->search([1.0, 0.0]);

        $this->assertEqualsWithDelta(1.0, $matches[0]->score, 1e-9);
        $this->assertEqualsWithDelta(1.0, $matches[1]->score, 1e-9);
    }

    public function test_the_similarity_floor_drops_weak_matches(): void
    {
        $store = $this->store();
        $store->add('near', [1.0, 0.0]);
        $store->add('far', [0.0, 1.0]);

        $matches = $store->search([1.0, 0.0], minSimilarity: 0.5);

        $this->assertCount(1, $matches);
        $this->assertSame('near', $matches[0]->id);
    }

    public function test_the_limit_is_respected(): void
    {
        $store = $this->store();

        foreach (range(1, 5) as $i) {
            $store->add('doc-' . $i, [(float) $i, 0.0]);
        }

        $this->assertCount(2, $store->search([1.0, 0.0], limit: 2));
    }

    public function test_metadata_travels_with_the_match(): void
    {
        $store = $this->store();
        $store->add('doc-1', [1.0, 0.0], ['text' => 'the cat sat', 'lang' => 'en']);

        $matches = $store->search([1.0, 0.0]);

        $this->assertSame('the cat sat', $matches[0]->metadata['text']);
        $this->assertSame(['id' => 'doc-1', 'score' => $matches[0]->score, 'metadata' => $matches[0]->metadata], $matches[0]->toArray());
    }

    public function test_a_metadata_filter_narrows_the_candidates(): void
    {
        $store = $this->store();
        $store->add('en', [1.0, 0.0], ['lang' => 'en']);
        $store->add('es', [1.0, 0.0], ['lang' => 'es']);

        $matches = $store->search([1.0, 0.0], filter: ['lang' => 'es']);

        $this->assertCount(1, $matches);
        $this->assertSame('es', $matches[0]->id);
    }

    /** Several constraints are ANDed, and a missing key is a non-match rather than an error. */
    public function test_several_filter_constraints_are_combined(): void
    {
        $store = $this->store();
        $store->add('both', [1.0, 0.0], ['lang' => 'en', 'tenant' => 7, 'public' => true]);
        $store->add('one', [1.0, 0.0], ['lang' => 'en']);
        $store->add('other-tenant', [1.0, 0.0], ['lang' => 'en', 'tenant' => 8]);

        $matches = $store->search([1.0, 0.0], filter: ['lang' => 'en', 'tenant' => 7]);

        $this->assertCount(1, $matches);
        $this->assertSame('both', $matches[0]->id);
    }

    // ── The guards, each of which prevents a wrong ANSWER rather than an error ──

    public function test_a_dimension_mismatch_on_add_is_refused(): void
    {
        $store = $this->store();
        $store->add('first', [1.0, 0.0, 0.0]);

        try {
            $store->add('second', [1.0, 0.0]);
            $this->fail('Expected a VectorStoreException.');
        } catch (VectorStoreException $e) {
            $this->assertStringContainsString('3-dimension', $e->getMessage());
            $this->assertStringContainsString('2-dimension', $e->getMessage());
        }
    }

    public function test_a_dimension_mismatch_on_search_is_refused_too(): void
    {
        $store = $this->store();
        $store->add('first', [1.0, 0.0, 0.0]);

        $this->expectException(VectorStoreException::class);

        $store->search([1.0, 0.0]);
    }

    public function test_an_empty_embedding_is_refused(): void
    {
        $this->expectException(VectorStoreException::class);

        $this->store()->add('empty', []);
    }

    public function test_a_zero_vector_is_refused_because_its_similarity_is_undefined(): void
    {
        try {
            $this->store()->add('zero', [0.0, 0.0]);
            $this->fail('Expected a VectorStoreException.');
        } catch (VectorStoreException $e) {
            $this->assertStringContainsString('undefined', $e->getMessage());
        }
    }

    public function test_a_non_numeric_component_is_refused(): void
    {
        $this->expectException(VectorStoreException::class);

        // @phpstan-ignore-next-line — deliberately wrong input
        $this->store()->add('bad', [1.0, 'two']);
    }

    /** Searching an empty store must not fix the dimension from the query and then reject a legitimate first add. */
    public function test_searching_an_empty_store_does_not_pin_the_dimension(): void
    {
        $store = $this->store();

        $this->assertSame([], $store->search([1.0, 0.0, 0.0, 0.0]));

        $store->add('first', [1.0, 0.0]);

        $this->assertSame(1, $store->count());
    }

    // ── Housekeeping ────────────────────────────────────────────────────────

    public function test_adding_the_same_id_twice_replaces_it(): void
    {
        $store = $this->store();
        $store->add('doc', [1.0, 0.0], ['version' => 1]);
        $store->add('doc', [1.0, 0.0], ['version' => 2]);

        $this->assertSame(1, $store->count());

        $matches = $store->search([1.0, 0.0]);
        $this->assertSame(2, $matches[0]->metadata['version']);
    }

    public function test_removing_an_absent_id_is_a_noop(): void
    {
        $store = $this->store();
        $store->add('doc', [1.0, 0.0]);

        $store->remove('never-stored');

        $this->assertSame(1, $store->count());
    }

    public function test_clear_empties_the_store_and_releases_the_dimension(): void
    {
        $store = $this->store();
        $store->add('doc', [1.0, 0.0, 0.0]);

        $store->clear();

        $this->assertSame(0, $store->count());

        // A different model means a different dimension; after a clear, that must be allowed.
        $store->add('other', [1.0, 0.0]);
        $this->assertSame(1, $store->count());
    }
}
