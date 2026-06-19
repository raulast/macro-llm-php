<?php

declare(strict_types=1);

namespace MacroLLM\Tool;

enum ToolStatus: string
{
    case Ok    = 'ok';
    case Error = 'error';
}
