<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Enums;

enum FinishReason: string
{
    case Stop = 'stop';
    case Length = 'length';
    case ToolCalls = 'tool_calls';
    case ContentFilter = 'content_filter';
    case Error = 'error';
    case Unknown = 'unknown';
}
