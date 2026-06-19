<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

enum ErrorStrategy
{
    case Stop;
    case Continue;
}
