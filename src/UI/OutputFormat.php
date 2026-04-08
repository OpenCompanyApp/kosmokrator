<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Output format for headless mode.
 *
 * Controls how the HeadlessRenderer writes results to stdout/stderr.
 */
enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case StreamJson = 'stream-json';
}
