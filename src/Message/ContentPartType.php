<?php

declare(strict_types=1);

namespace MacroLLM\Message;

enum ContentPartType: string
{
    case Text        = 'text';
    case ImageUrl    = 'image_url';
    case ImageBase64 = 'image_base64';
}
