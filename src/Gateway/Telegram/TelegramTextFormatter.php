<?php

declare(strict_types=1);

namespace Kosmokrator\Gateway\Telegram;

final class TelegramTextFormatter
{
    /**
     * @return list<string>
     */
    public static function splitPlainText(string $text, int $limit = 3600): array
    {
        $normalized = trim(str_replace("\r\n", "\n", $text));
        if ($normalized === '') {
            return [];
        }

        $chunks = [];
        $remaining = $normalized;

        while (self::utf16Length($remaining) > $limit) {
            $slice = self::prefixWithinUtf16Limit($remaining, $limit);
            $splitAt = max(
                (int) mb_strrpos($slice, "\n\n"),
                (int) mb_strrpos($slice, "\n"),
                (int) mb_strrpos($slice, ' '),
            );

            if ($splitAt < (int) ($limit / 2)) {
                $splitAt = mb_strlen($slice);
            }

            $chunks[] = trim(mb_substr($remaining, 0, $splitAt));
            $remaining = ltrim(mb_substr($remaining, $splitAt));
        }

        if ($remaining !== '') {
            $chunks[] = trim($remaining);
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => $chunk !== ''));
    }

    public static function utf16Length(string $text): int
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
    }

    public static function prefixWithinUtf16Limit(string $text, int $limit): string
    {
        if (self::utf16Length($text) <= $limit) {
            return $text;
        }

        $low = 0;
        $high = mb_strlen($text);
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if (self::utf16Length(mb_substr($text, 0, $mid)) <= $limit) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return mb_substr($text, 0, $low);
    }

    public static function formatHtml(string $text): string
    {
        $source = str_replace("\r\n", "\n", trim($text));
        if ($source === '') {
            return '…';
        }

        $placeholders = [];
        $index = 0;

        $source = self::protectMarkdownTables($source, $placeholders, $index);

        $source = preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)```/s', static function (array $matches) use (&$placeholders, &$index): string {
            $token = "@@KOSMO_BLOCK_{$index}@@";
            $code = htmlspecialchars(rtrim($matches[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $placeholders[$token] = '<pre><code>'.$code.'</code></pre>';
            $index++;

            return $token;
        }, $source) ?? $source;

        $source = preg_replace_callback('/`([^`\n]+)`/', static function (array $matches) use (&$placeholders, &$index): string {
            $token = "@@KOSMO_INLINE_{$index}@@";
            $code = htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $placeholders[$token] = '<code>'.$code.'</code>';
            $index++;

            return $token;
        }, $source) ?? $source;

        $escaped = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*(.+?)\*/s', '<i>$1</i>', $escaped) ?? $escaped;

        return strtr($escaped, $placeholders);
    }

    public static function stripHtml(string $html): string
    {
        $text = str_replace(
            ['<pre><code>', '</code></pre>', '<code>', '</code>', '<b>', '</b>', '<i>', '</i>'],
            ['', '', '`', '`', '', '', '', ''],
            $html,
        );
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return trim($text);
    }

    public static function formatToolSummary(string $name, array $args): string
    {
        $lines = [
            '<b>Tool</b> <code>'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>',
        ];

        if ($args !== []) {
            $json = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json)) {
                if (mb_strlen($json) > 2800) {
                    $json = mb_substr($json, 0, 2797).'...';
                }
                $lines[] = '<pre><code>'.htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
            }
        }

        return implode("\n", $lines);
    }

    public static function formatToolResult(string $name, string $output, bool $success): string
    {
        $label = $success ? 'done' : 'failed';
        $lines = [
            '<b>Tool '.$label.'</b> <code>'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>',
        ];

        $trimmed = trim($output);
        if ($trimmed !== '') {
            if (mb_strlen($trimmed) > 2800) {
                $trimmed = mb_substr($trimmed, 0, 2797).'...';
            }
            $lines[] = '<pre><code>'.htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private static function protectMarkdownTables(string $source, array &$placeholders, int &$index): string
    {
        $lines = explode("\n", $source);
        $result = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (
                isset($lines[$i + 1])
                && str_contains($line, '|')
                && preg_match('/^\s*\|?[\s:-]+\|[\s|:-]*$/', $lines[$i + 1]) === 1
            ) {
                $tableLines = [$line, $lines[$i + 1]];
                $i += 2;

                while ($i < $count && str_contains($lines[$i], '|')) {
                    $tableLines[] = $lines[$i];
                    $i++;
                }

                $i--;
                $token = "@@KOSMO_TABLE_{$index}@@";
                $placeholders[$token] = '<pre><code>'.htmlspecialchars(implode("\n", $tableLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
                $result[] = $token;

                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result);
    }
}
