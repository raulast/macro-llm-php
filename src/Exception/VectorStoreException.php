<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

/**
 * A vector store was asked for something it cannot do, and each reason is one that would otherwise produce a
 * plausible-looking wrong answer rather than an error.
 */
final class VectorStoreException extends MacroLLMException
{
    public static function dimensionMismatch(int $expected, int $given): self
    {
        return new self(sprintf(
            'This store holds %d-dimension vectors and was given a %d-dimension one. Comparing vectors of different '
            . 'lengths produces numbers that look like similarity scores and mean nothing, so it is refused here '
            . 'instead. Index a different store per embedding model.',
            $expected,
            $given,
        ));
    }

    public static function emptyEmbedding(): self
    {
        return new self(
            'An embedding cannot be empty: there is nothing to compare, and an empty vector would score against '
            . 'nothing rather than fail.',
        );
    }

    public static function zeroVector(): self
    {
        return new self(
            'An all-zero embedding has no direction, so its cosine similarity is undefined rather than zero. The '
            . 'usual cause is a provider that failed quietly; check the embedding before indexing it.',
        );
    }

    public static function nonNumericComponent(int $index, mixed $value): self
    {
        return new self(sprintf(
            'The embedding component at index %d is %s, not a number. A single bad component makes every score '
            . 'involving this vector meaningless.',
            $index,
            get_debug_type($value),
        ));
    }
}
