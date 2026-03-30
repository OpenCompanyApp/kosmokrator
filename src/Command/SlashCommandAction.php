<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

enum SlashCommandAction
{
    case Continue;
    case Quit;
    case Inject;
}
