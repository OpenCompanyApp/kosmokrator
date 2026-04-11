<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

use Illuminate\Container\Container;
use Kosmokrator\Command\SlashCommandAction;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandRegistryFactory;

final class TelegramSlashCommandBridge
{
    public function __construct(
        private readonly Container $container,
        private readonly string $version = 'dev',
    ) {}

    public function dispatch(string $input, SlashCommandContext $ctx): ?string
    {
        $trimmed = trim($input);
        if (! str_starts_with($trimmed, '/')) {
            return null;
        }

        $registry = SlashCommandRegistryFactory::build($this->container, $this->version);
        $command = $registry->resolve($trimmed);

        if ($command === null) {
            $name = preg_split('/\s+/', $trimmed, 2)[0] ?: $trimmed;
            $ctx->ui->showNotice("Unknown command: {$name}");

            return '';
        }

        if (! in_array($command->name(), TelegramBotCommandCatalog::supportedSlashCommands(), true)) {
            $ctx->ui->showNotice("{$command->name()} is not available in Telegram.");

            return '';
        }

        $args = $registry->extractArgs($trimmed, $command);
        $result = $command->execute($args, $ctx);

        return match ($result->action) {
            SlashCommandAction::Continue => '',
            SlashCommandAction::Inject => $result->input ?? '',
            SlashCommandAction::Quit => '',
        };
    }
}
