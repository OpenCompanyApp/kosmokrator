<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

/**
 * Enumerates the possible outcomes of a slash command: continue the REPL, quit, or inject a message.
 */
enum SlashCommandAction
{
    case Continue;
    case Quit;
    case Inject;
}
