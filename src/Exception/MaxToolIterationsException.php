<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

use MacroLLM\Message\InternalResponse;

final class MaxToolIterationsException extends MacroLLMException
{
    public function __construct(
        public readonly int $iterations,
        public readonly InternalResponse $lastResponse,
    ) {
        parent::__construct(
            sprintf('Agent reached maximum tool iterations (%d) without a stop finish reason.', $iterations),
        );
    }
}
