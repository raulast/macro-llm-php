<?php

declare(strict_types=1);

namespace MacroLLM\Orchestration;

enum RoutingStrategy
{
    case Sequential;
    case Parallel;
    case Conditional;
}
