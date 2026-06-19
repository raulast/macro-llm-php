<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

use MacroLLM\Message\StreamChunk;

final class StreamInterruptedException extends MacroLLMException
{
    /** @param StreamChunk[] $chunks */
    public function __construct(
        public readonly array $chunks,
    ) {
        parent::__construct(
            sprintf('Stream interrupted after %d chunk(s) without receiving a finish event.', count($chunks)),
        );
    }
}
