<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

use MacroLLM\Provider\FailoverPolicy;

/**
 * Every provider in the failover chain failed.
 *
 * This exists because the alternative loses the information the caller needs. Throwing only the last provider's
 * failure would say "anthropic was rate limited" and hide that openai was tried first and failed for a different
 * reason — which is exactly the diagnostic a chain of providers exists to produce.
 *
 * `causes` is keyed by provider name, in the order they were tried. The previous exception is the last cause, so the
 * ordinary exception chain still works for anyone who only looks at `getPrevious()`.
 */
final class ProviderFailoverException extends MacroLLMException
{
    /** @param array<string, \Throwable> $causes */
    public function __construct(
        public readonly array $causes,
        public readonly array $chain,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  list<string>               $chain
     * @param  array<string, \Throwable>  $causes
     */
    public static function exhausted(array $chain, array $causes): self
    {
        $reported = [];

        foreach ($causes as $name => $cause) {
            $reported[] = sprintf('%s (%s)', $name, FailoverPolicy::reason($cause));
        }

        return new self(
            causes: $causes,
            chain: $chain,
            message: sprintf(
                'Every provider in the failover chain failed: %s. Each cause is preserved, because knowing that '
                . 'the first provider was rate limited and the second was down is the whole point of having a '
                . 'chain.',
                implode(', ', $reported),
            ),
            previous: $causes === [] ? null : end($causes),
        );
    }
}
