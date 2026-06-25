<?php

declare(strict_types=1);

namespace MacroLLM\Contract;

use MacroLLM\Message\ImageRequest;
use MacroLLM\Message\ImageResponse;

interface ImageProviderInterface
{
    public function name(): string;
    public function generate(ImageRequest $request): ImageResponse;
}
