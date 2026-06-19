<?php

declare(strict_types=1);

namespace MacroLLM\Message;

enum FinishReason: string
{
    case Stop          = 'stop';
    case ToolCalls     = 'tool_calls';
    case Length        = 'length';
    case ContentFilter = 'content_filter';
    case Error         = 'error';
}
