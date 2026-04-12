<?php

declare(strict_types=1);

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentMode;

final class SlashCommandRegistryFactory
{
    public static function build(
        Container $container,
        string $version = 'dev',
        bool $includeHelp = false,
        ?PowerCommandRegistry $powerRegistry = null,
    ): SlashCommandRegistry {
        $registry = new SlashCommandRegistry;

        $registry->register(new Slash\QuitCommand);
        $registry->register(new Slash\ClearCommand);
        $registry->register(new Slash\SeedCommand);
        $registry->register(new Slash\TheogonyCommand);
        $registry->register(new Slash\CompactCommand);
        $registry->register(new Slash\ModelsCommand($container));
        $registry->register(new Slash\TasksClearCommand);
        $registry->register(new Slash\MemoriesCommand);
        $registry->register(new Slash\SessionsCommand);
        $registry->register(new Slash\ForgetCommand);

        $registry->register(new Slash\GuardianCommand);
        $registry->register(new Slash\ArgusCommand);
        $registry->register(new Slash\PrometheusCommand);
        $registry->register(new Slash\ModeCommand(AgentMode::Edit));
        $registry->register(new Slash\ModeCommand(AgentMode::Plan));
        $registry->register(new Slash\ModeCommand(AgentMode::Ask));

        $registry->register(new Slash\NewCommand);
        $registry->register(new Slash\ResumeCommand);
        $registry->register(new Slash\SettingsCommand($container));
        $registry->register(new Slash\AgentsCommand);
        $registry->register(new Slash\FeedbackCommand($version));
        $registry->register(new Slash\RenameCommand);

        if ($includeHelp) {
            $registry->register(new Slash\HelpCommand($registry, $powerRegistry ?? new PowerCommandRegistry));
        }

        return $registry;
    }
}
