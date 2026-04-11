<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

final class TelegramBotCommandCatalog
{
    /**
     * @return list<array{command: string, description: string}>
     */
    public static function nativeCommands(): array
    {
        return [
            ['command' => 'help', 'description' => 'Show gateway help'],
            ['command' => 'status', 'description' => 'Show linked session status'],
            ['command' => 'new', 'description' => 'Start a fresh chat session'],
            ['command' => 'resume', 'description' => 'Resume the linked session'],
            ['command' => 'approve', 'description' => 'Approve the latest tool request'],
            ['command' => 'deny', 'description' => 'Deny the latest tool request'],
            ['command' => 'cancel', 'description' => 'Cancel the active run'],
        ];
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    public static function systemCommands(): array
    {
        return [
            ['command' => 'compact', 'description' => 'Force context compaction'],
            ['command' => 'edit', 'description' => 'Switch to edit mode'],
            ['command' => 'plan', 'description' => 'Switch to plan mode'],
            ['command' => 'ask', 'description' => 'Switch to ask mode'],
            ['command' => 'guardian', 'description' => 'Switch to Guardian mode'],
            ['command' => 'argus', 'description' => 'Switch to Argus mode'],
            ['command' => 'prometheus', 'description' => 'Switch to Prometheus mode'],
            ['command' => 'memories', 'description' => 'List stored memories'],
            ['command' => 'sessions', 'description' => 'List recent sessions'],
            ['command' => 'agents', 'description' => 'Show swarm summary'],
            ['command' => 'rename', 'description' => 'Rename the current session'],
            ['command' => 'forget', 'description' => 'Delete a memory by ID'],
        ];
    }

    /**
     * @return list<array{command: string, description: string}>
     */
    public static function commands(): array
    {
        return [...self::nativeCommands(), ...self::systemCommands()];
    }

    /**
     * @return list<string>
     */
    public static function supportedSlashCommands(): array
    {
        return array_map(
            static fn (array $command): string => '/'.$command['command'],
            self::systemCommands(),
        );
    }

    public static function helpText(): string
    {
        $lines = ['KosmoKrator Telegram gateway', '', 'Telegram commands:'];

        foreach (self::nativeCommands() as $command) {
            $lines[] = sprintf('/%s — %s', $command['command'], $command['description']);
        }

        $lines[] = '';
        $lines[] = 'Kosmo commands:';
        foreach (self::systemCommands() as $command) {
            $lines[] = sprintf('/%s — %s', $command['command'], $command['description']);
        }

        return implode("\n", $lines);
    }
}
