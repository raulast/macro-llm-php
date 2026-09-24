<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

/**
 * A test double was asked for something it cannot provide.
 *
 * A fake must fail loudly rather than approximate. Two cases, both of which would be worse if guessed:
 *
 *  - a provider whose wire format no template covers yet — inventing one would produce a payload the real adapter
 *    cannot parse, so the test would fail somewhere confusing instead of here;
 *  - a queue that has run dry — inventing a response makes a test green for a reason its author never intended.
 */
final class FakeGatewayException extends MacroLLMException
{
    public static function noResponsesQueued(string $provider, int $callNumber): self
    {
        return new self(sprintf(
            'The fake for "%s" has no queued responses left, and this was call #%d. Queue one more response, or assert '
            . 'the call count — but do not let the fake invent an answer, because a test green for the wrong reason is '
            . 'worse than a red one.',
            $provider,
            $callNumber,
        ));
    }

    public static function unknownProvider(string $provider): self
    {
        return new self(sprintf(
            '"%s" is not a provider this package knows, so there is nothing to fake. Use one of the names in '
            . 'ProviderFactory, or register your own provider and test it directly.',
            $provider,
        ));
    }

    public static function noTemplateFor(string $provider): self
    {
        return new self(sprintf(
            'There is no wire template for "%s" yet, so a fake cannot answer in the shape its adapter parses. '
            . 'Supported families so far: OpenAI-compatible. Anthropic, Gemini and Cohere are next; until then, fake '
            . 'this provider at the HTTP layer yourself with the shape from its adapter.',
            $provider,
        ));
    }
}
