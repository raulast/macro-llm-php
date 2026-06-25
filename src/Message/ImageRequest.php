<?php

declare(strict_types=1);

namespace MacroLLM\Message;

final class ImageRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly ImageSize $size = ImageSize::Square,
        public readonly ?string $quality = null,  // 'standard'|'hd' (OpenAI), 'high'|'medium'|'low' (Gemini)
        public readonly int $n = 1,
        public readonly ?string $model = null,
    ) {}
}
