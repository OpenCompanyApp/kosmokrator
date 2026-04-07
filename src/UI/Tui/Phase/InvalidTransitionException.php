<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Phase;

/**
 * Thrown when an invalid phase transition is attempted.
 */
final class InvalidTransitionException extends \LogicException
{
    public static function fromTo(Phase $from, Phase $to): self
    {
        return new self(
            \sprintf('Invalid phase transition: %s → %s', $from->value, $to->value),
        );
    }
}
