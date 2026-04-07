<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

/**
 * Represents a conflict detected between two keybindings in the same context.
 *
 * Value object — immutable after construction.
 */
final class Conflict
{
    public function __construct(
        public readonly string $context,
        public readonly string $action1,
        public readonly string $action2,
        public readonly string $conflictingKey,
    ) {}

    public function __toString(): string
    {
        return \sprintf(
            'Conflict in "%s" context: key "%s" bound to both "%s" and "%s"',
            $this->context,
            $this->conflictingKey,
            $this->action1,
            $this->action2,
        );
    }
}
