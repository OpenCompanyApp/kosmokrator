<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding\Patch;

/**
 * Parses the custom *** Begin Patch / *** End Patch text format into PatchOperation DTOs.
 *
 * This is the parsing frontend for the apply_patch tool — the format uses a simple
 * line-based protocol with *** headers and unified-diff-style body prefixes.
 */
final class PatchParser
{
    /**
     * Parse a raw patch string into an ordered list of operations.
     *
     * @param  string  $patch  Raw patch text bounded by *** Begin Patch / *** End Patch
     * @return PatchOperation[]
     */
    public function parse(string $patch): array
    {
        $patch = str_replace("\r\n", "\n", trim($patch));
        if ($patch === '') {
            throw new \InvalidArgumentException('Patch cannot be empty.');
        }

        $lines = explode("\n", $patch);
        if ($lines[0] !== '*** Begin Patch') {
            throw new \InvalidArgumentException('Patch must start with "*** Begin Patch".');
        }

        if ($lines[array_key_last($lines)] !== '*** End Patch') {
            throw new \InvalidArgumentException('Patch must end with "*** End Patch".');
        }

        $operations = [];
        $index = 1;
        $last = count($lines) - 1;

        while ($index < $last) {
            $line = $lines[$index];
            if ($line === '') {
                $index++;

                continue;
            }

            if (str_starts_with($line, '*** Add File: ')) {
                [$operation, $index] = $this->parseAdd($lines, $index, $last);
                $operations[] = $operation;

                continue;
            }

            if (str_starts_with($line, '*** Update File: ')) {
                [$operation, $index] = $this->parseUpdate($lines, $index, $last);
                $operations[] = $operation;

                continue;
            }

            if (str_starts_with($line, '*** Delete File: ')) {
                $path = trim(substr($line, strlen('*** Delete File: ')));
                if ($path === '') {
                    throw new \InvalidArgumentException('Delete operation requires a file path.');
                }
                $operations[] = new PatchOperation('delete', $path);
                $index++;

                continue;
            }

            throw new \InvalidArgumentException("Unexpected patch line: {$line}");
        }

        if ($operations === []) {
            throw new \InvalidArgumentException('Patch did not contain any operations.');
        }

        return $operations;
    }

    /**
     * Convenience method: parse a patch and return all file paths it touches (including move destinations).
     *
     * @param  string  $patch  Raw patch text
     * @return string[] Unique list of file paths
     */
    public function extractTargetPaths(string $patch): array
    {
        $paths = [];
        foreach ($this->parse($patch) as $operation) {
            $paths[] = $operation->path;
            if ($operation->moveTo !== null) {
                $paths[] = $operation->moveTo;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Parse an *** Add File section: every body line must start with '+'.
     *
     * @param  string[]  $lines
     * @return array{PatchOperation, int} [operation, next line index]
     */
    private function parseAdd(array $lines, int $index, int $last): array
    {
        $path = trim(substr($lines[$index], strlen('*** Add File: ')));
        if ($path === '') {
            throw new \InvalidArgumentException('Add operation requires a file path.');
        }

        $index++;
        $body = [];
        while ($index < $last && ! $this->isHeader($lines[$index])) {
            $line = $lines[$index];
            if ($line === '*** End of File') {
                $index++;

                continue;
            }
            if (! str_starts_with($line, '+')) {
                throw new \InvalidArgumentException("Add file lines must start with '+': {$line}");
            }
            $body[] = substr($line, 1);
            $index++;
        }

        return [new PatchOperation('add', $path, $body), $index];
    }

    /**
     * Parse an *** Update File section, optionally including a *** Move to directive and hunk body.
     *
     * @param  string[]  $lines
     * @return array{PatchOperation, int} [operation, next line index]
     */
    private function parseUpdate(array $lines, int $index, int $last): array
    {
        $path = trim(substr($lines[$index], strlen('*** Update File: ')));
        if ($path === '') {
            throw new \InvalidArgumentException('Update operation requires a file path.');
        }

        $index++;
        $moveTo = null;
        if ($index < $last && str_starts_with($lines[$index], '*** Move to: ')) {
            $moveTo = trim(substr($lines[$index], strlen('*** Move to: ')));
            if ($moveTo === '') {
                throw new \InvalidArgumentException('Move operation requires a destination path.');
            }
            $index++;
        }

        $body = [];
        while ($index < $last && ! $this->isHeader($lines[$index])) {
            $line = $lines[$index];
            if ($line === '*** End of File') {
                $body[] = $line;
                $index++;

                continue;
            }
            if ($line === '@@' || str_starts_with($line, '@@ ')) {
                $body[] = $line;
                $index++;

                continue;
            }
            if ($line === '') {
                throw new \InvalidArgumentException('Patch body lines must include a prefix character.');
            }

            $prefix = $line[0];
            if (! in_array($prefix, [' ', '+', '-'], true)) {
                throw new \InvalidArgumentException("Unexpected update line prefix '{$prefix}' in '{$line}'.");
            }

            $body[] = $line;
            $index++;
        }

        return [new PatchOperation('update', $path, $body, $moveTo), $index];
    }

    /** Check whether a line is a top-level operation header (Add/Update/Delete). */
    private function isHeader(string $line): bool
    {
        return str_starts_with($line, '*** Add File: ')
            || str_starts_with($line, '*** Update File: ')
            || str_starts_with($line, '*** Delete File: ');
    }
}
