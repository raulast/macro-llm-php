<?php

declare(strict_types=1);

namespace MacroLLM\Message;

enum ImageSize: string
{
    case Square    = 'square';
    case Portrait  = 'portrait';
    case Landscape = 'landscape';
}
