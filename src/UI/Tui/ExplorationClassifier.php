<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

/**
 * Classifies tool calls as read-only "exploration/omens" operations.
 *
 * Used by TuiRenderer to batch read-only tool calls together in the conversation
 * display via DiscoveryBatchWidget, separate from write/execute tools.
 */
final class ExplorationClassifier
{
    private const SHELL_META_PATTERN = '/[;&|`$><\n]/';

    private const OMENS_TOOLS = [
        'file_read',
        'glob',
        'grep',
        'memory_search',
    ];

    private const EXPLORATORY_BASH_PREFIXES = [
        'rg',
        'grep',
        'find',
        'fd',
        'ls',
        'tree',
        'cat',
        'head',
        'tail',
        'sed -n',
        'wc',
        'git status',
        'git diff',
        'git show',
        'git log',
        'git branch',
        'git rev-parse',
        'pwd',
        'which',
        'whereis',
        'realpath',
        'readlink',
        'php -v',
        'php --version',
        'composer show',
        'composer why',
        'composer outdated',
    ];

    /**
     * @param  string  $name  Tool name being invoked
     * @param  array   $args  Tool invocation arguments
     * @return bool Whether the tool call is a read-only exploration operation
     */
    public static function isOmensTool(string $name, array $args): bool
    {
        if (in_array($name, self::OMENS_TOOLS, true)) {
            return true;
        }

        if ($name !== 'bash') {
            return false;
        }

        return self::isExploratoryBashCommand((string) ($args['command'] ?? ''));
    }

    /**
     * @param  string  $command  The shell command to classify
     * @return bool Whether the command is a read-only exploratory operation
     */
    public static function isExploratoryBashCommand(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }

        if ((bool) preg_match(self::SHELL_META_PATTERN, $command)) {
            return false;
        }

        $lower = strtolower($command);
        foreach (self::EXPLORATORY_BASH_PREFIXES as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix.' ')) {
                return true;
            }
        }

        return false;
    }
}
