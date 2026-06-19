<?php

declare(strict_types=1);

namespace MacroLLM\Exception;

final class ContainerBindingException extends MacroLLMException
{
    public function __construct(
        public readonly string $containerClass,
    ) {
        parent::__construct(
            sprintf(
                'Cannot bind into container "%s": container does not support write operations.',
                $containerClass,
            ),
        );
    }
}
