<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\UI\Theme;

/**
 * Builds a structured preview of a tool-call permission request for the TUI approval screen.
 *
 * Produces the title, summary, scope description, and content preview shown to the operator
 * before approving or denying a tool invocation (e.g. shell commands, file edits, patches).
 */
final class PermissionPreviewBuilder
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{
     *     title: string,
     *     tool_label: string,
     *     summary: string,
     *     sections: list<array{label: string, lines: list<string>}>
     * }
     */
    public function build(string $toolName, array $args): array
    {
        return [
            'title' => $this->titleFor($toolName),
            'tool_label' => Theme::toolLabel($toolName),
            'summary' => $this->summaryFor($toolName),
            'sections' => array_values(array_filter([
                $this->primarySection($toolName, $args),
                $this->scopeSection($toolName, $args),
                $this->previewSection($toolName, $args),
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, lines: list<string>}|null
     */
    private function primarySection(string $toolName, array $args): ?array
    {
        return match ($toolName) {
            'bash' => $this->section('Command', [(string) ($args['command'] ?? '')]),
            'shell_start' => $this->section('Command', [(string) ($args['command'] ?? $args['input'] ?? '')]),
            'shell_write' => $this->section('Input', [(string) ($args['input'] ?? '')]),
            'shell_read', 'shell_kill' => $this->section('Session', [$this->sessionLabel($args)]),
            'file_read', 'file_write', 'file_edit' => $this->section('File', [$this->pathLabel($args)]),
            'apply_patch' => $this->section('Files', $this->patchFiles((string) ($args['patch'] ?? ''))),
            default => isset($args['path']) ? $this->section('Target', [$this->pathLabel($args)]) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, lines: list<string>}
     */
    private function scopeSection(string $toolName, array $args): array
    {
        return match ($toolName) {
            'bash' => $this->section('Scope', [$this->bashScope((string) ($args['command'] ?? ''))]),
            'shell_start' => $this->section('Scope', ['opens a persistent shell session']),
            'shell_write' => $this->section('Scope', ['sends input into an existing shell session']),
            'shell_read' => $this->section('Scope', ['reads output from an existing shell session']),
            'shell_kill' => $this->section('Scope', ['terminates an existing shell session']),
            'file_read', 'grep', 'glob' => $this->section('Scope', ['reads workspace files']),
            'file_write', 'file_edit', 'apply_patch' => $this->section('Scope', ['writes workspace files']),
            default => $this->section('Scope', ['executes this tool call in the current workspace']),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{label: string, lines: list<string>}|null
     */
    private function previewSection(string $toolName, array $args): ?array
    {
        return match ($toolName) {
            'bash' => $this->section('Expected result', [$this->expectedResultForBash((string) ($args['command'] ?? ''))]),
            'file_write' => $this->section('Preview', $this->previewText((string) ($args['content'] ?? ''))),
            'file_edit' => $this->section('Preview', $this->previewEdit($args)),
            'apply_patch' => $this->section('Preview', $this->previewPatch((string) ($args['patch'] ?? ''))),
            'shell_write' => $this->section('Preview', $this->previewText((string) ($args['input'] ?? ''))),
            default => null,
        };
    }

    /** Returns the dialog title based on whether the tool modifies files. */
    private function titleFor(string $toolName): string
    {
        return in_array($toolName, ['file_write', 'file_edit', 'apply_patch'], true)
            ? 'Edit Approval'
            : 'Invocation Request';
    }

    /** Returns a human-readable one-line description of what the tool does. */
    private function summaryFor(string $toolName): string
    {
        return match ($toolName) {
            'bash' => 'Execute a shell command in the current workspace',
            'shell_start' => 'Start a live shell session',
            'shell_write' => 'Send input to a live shell session',
            'shell_read' => 'Read output from a live shell session',
            'shell_kill' => 'Terminate a live shell session',
            'file_read' => 'Read file contents from the workspace',
            'file_write' => 'Write file contents in the workspace',
            'file_edit' => 'Edit an existing file in the workspace',
            'apply_patch' => 'Apply a structured multi-file patch to the workspace',
            default => 'Execute this tool call',
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function previewEdit(array $args): array
    {
        $old = (string) ($args['old_string'] ?? '');
        $new = (string) ($args['new_string'] ?? '');

        if (trim($old) === '' && trim($new) === '') {
            return ['edits existing file content'];
        }

        $lines = [];
        $maxLines = 6;

        // Show removed lines (red-tinted)
        foreach (preg_split('/\R/', $old) ?: [] as $oldLine) {
            $trimmed = trim($oldLine);
            if ($trimmed === '') {
                continue;
            }
            $lines[] = Theme::error().'- '.$this->truncateLine($trimmed);
            if (count($lines) >= $maxLines) {
                break;
            }
        }

        // Show added lines (green-tinted)
        $removedCount = count($lines);
        foreach (preg_split('/\R/', $new) ?: [] as $newLine) {
            $trimmed = trim($newLine);
            if ($trimmed === '') {
                continue;
            }
            $lines[] = Theme::success().'+ '.$this->truncateLine($trimmed);
            if (count($lines) >= $maxLines + $removedCount) {
                break;
            }
        }

        // Cap total lines
        $lines = array_slice($lines, 0, $maxLines);

        return $lines !== [] ? $lines : ['edits existing file content'];
    }

    /**
     * @return list<string>
     */
    private function previewText(string $text): array
    {
        $allLines = preg_split('/\R/', trim($text)) ?: [];
        $maxPreview = 8;
        $lines = [];

        foreach ($allLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $lines[] = Theme::text().$this->truncateLine($trimmed);
            if (count($lines) === $maxPreview) {
                break;
            }
        }

        $totalNonEmpty = count(array_filter($allLines, fn (string $l): bool => trim($l) !== ''));
        if ($totalNonEmpty > $maxPreview) {
            $remaining = $totalNonEmpty - $maxPreview;
            $lines[] = Theme::dim()."… ({$remaining} more lines)";
        }

        return $lines !== [] ? $lines : ['no preview available'];
    }

    /**
     * @return list<string>
     */
    private function patchFiles(string $patch): array
    {
        $files = [];
        foreach (preg_split('/\R/', $patch) ?: [] as $line) {
            if (preg_match('/^\*\*\* (?:Add|Update|Delete) File: (.+)$/', $line, $matches)) {
                $files[] = $matches[1];
            } elseif (preg_match('/^\*\*\* Move to: (.+)$/', $line, $matches)) {
                $files[] = 'move -> '.$matches[1];
            }

            if (count($files) === 4) {
                break;
            }
        }

        return $files !== [] ? $files : ['structured patch'];
    }

    /**
     * @return list<string>
     */
    private function previewPatch(string $patch): array
    {
        $preview = [];
        $maxLines = 10;
        foreach (preg_split('/\R/', $patch) ?: [] as $line) {
            $trimmed = rtrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '*** ')) {
                continue;
            }

            if ($trimmed === '@@' || str_starts_with($trimmed, '@@ ')) {
                $preview[] = Theme::dim().$this->truncateLine($trimmed);
            } elseif ($trimmed[0] === '+') {
                $preview[] = Theme::success().$this->truncateLine($trimmed);
            } elseif ($trimmed[0] === '-') {
                $preview[] = Theme::error().$this->truncateLine($trimmed);
            } elseif (in_array($trimmed[0], ['+', '-', ' '], true)) {
                $preview[] = Theme::text().$this->truncateLine($trimmed);
            }

            if (count($preview) === $maxLines) {
                break;
            }
        }

        return $preview !== [] ? $preview : ['structured patch with file updates'];
    }

    /** Infers the expected outcome of a bash command from its content. */
    private function expectedResultForBash(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return 'runs the requested shell command';
        }
        if (preg_match('/\b(?:phpunit|pest|npm test|pnpm test|yarn test)\b/i', $command)) {
            return 'runs targeted tests and reports the result';
        }
        if (preg_match('/\b(?:git status|git diff|ls|cat|sed|rg|find)\b/i', $command)) {
            return 'inspects repository state and prints the result';
        }

        return 'runs the requested shell command and prints the result';
    }

    /** Determines the filesystem scope of a bash command (read-only vs. write). */
    private function bashScope(string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            return 'shell access in the current workspace';
        }
        if (preg_match('/(^|[[:space:]])(?:rm|mv|cp|mkdir|touch|tee|sed -i|perl -pi|git checkout|git clean|chmod|chown)\b/', $command)) {
            return 'shell access with potential filesystem writes';
        }
        if (str_contains($command, '>') || str_contains($command, '>>')) {
            return 'shell access with redirected filesystem writes';
        }

        return 'shell access with output returned to the session';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function pathLabel(array $args): string
    {
        return Theme::relativePath((string) ($args['path'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function sessionLabel(array $args): string
    {
        $id = (string) ($args['session_id'] ?? '');

        return $id !== '' ? "session {$id}" : 'current shell session';
    }

    /**
     * @param  list<string>  $lines
     * @return array{label: string, lines: list<string>}|null
     */
    private function section(string $label, array $lines): ?array
    {
        $filtered = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines
        ), static fn (string $line): bool => $line !== ''));

        if ($filtered === []) {
            return null;
        }

        return [
            'label' => $label,
            'lines' => $filtered,
        ];
    }

    /** Returns the first non-empty line from a multi-line string. */
    private function firstMeaningfulLine(string $text): string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return trim($text);
    }

    /** Truncates a line to the given display width, appending an ellipsis if needed. */
    private function truncateLine(string $line, int $limit = 96): string
    {
        return mb_strwidth($line) > $limit
            ? rtrim(mb_strimwidth($line, 0, $limit - 1, '…'))
            : $line;
    }
}
