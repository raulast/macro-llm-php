<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class TranscriptionRequest
{
    public function __construct(
        public readonly string $filePath,
        public readonly ?string $language = null,
        public readonly ?string $model = null,
    ) {}
}
