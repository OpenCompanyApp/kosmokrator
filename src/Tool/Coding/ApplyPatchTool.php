<?php

declare(strict_types=1);

namespace Kosmokrator\Tool\Coding;

use Kosmokrator\Tool\Coding\Patch\PatchApplier;
use Kosmokrator\Tool\Coding\Patch\PatchParser;
use Kosmokrator\Tool\ToolInterface;
use Kosmokrator\Tool\ToolResult;

/**
 * Applies structured patches for multi-hunk, multi-file edits (add, update, delete, move).
 * Prefer this over multiple FileEditTool calls when changing several files or hunks at once.
 * Delegates parsing to PatchParser and application to PatchApplier.
 */
final class ApplyPatchTool implements ToolInterface
{
    public function __construct(
        private readonly PatchParser $parser,
        private readonly PatchApplier $applier,
    ) {}

    public function name(): string
    {
        return 'apply_patch';
    }

    public function description(): string
    {
        return 'Structured patch for multi-hunk or multi-file edits, adds, deletes, or moves. Use file_edit for one exact replacement in one file.';
    }

    public function parameters(): array
    {
        return [
            'patch' => [
                'type' => 'string',
                'description' => 'Patch text using *** Begin Patch / *** End Patch blocks with Add File, Update File, and Delete File operations.',
            ],
        ];
    }

    public function requiredParameters(): array
    {
        return ['patch'];
    }

    /**
     * @param  array{patch: string}  $args  Patch text with *** Begin Patch / *** End Patch blocks
     * @return ToolResult Success summary (added/updated/deleted/moved counts) or error message
     */
    public function execute(array $args): ToolResult
    {
        $patch = (string) ($args['patch'] ?? '');
        if (trim($patch) === '') {
            return ToolResult::error('Patch cannot be empty.');
        }

        try {
            $operations = $this->parser->parse($patch);
            $summary = $this->applier->apply($operations);

            $parts = [];
            if ($summary['added'] > 0) {
                $parts[] = "added {$summary['added']} file(s)";
            }
            if ($summary['updated'] > 0) {
                $parts[] = "updated {$summary['updated']} file(s)";
            }
            if ($summary['deleted'] > 0) {
                $parts[] = "deleted {$summary['deleted']} file(s)";
            }
            if ($summary['moved'] > 0) {
                $parts[] = "moved {$summary['moved']} file(s)";
            }

            return ToolResult::success('Patch applied: '.implode(', ', $parts));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
