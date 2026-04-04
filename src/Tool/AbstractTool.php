<?php

declare(strict_types=1);

namespace Kosmokrator\Tool;

use Kosmokrator\Agent\ErrorSanitizer;

/**
 * Base class for tool implementations, providing automatic error wrapping and
 * a sensible default for requiredParameters() (all parameter keys).
 *
 * Subclasses implement handle() instead of execute(). Any uncaught exception
 * thrown from handle() is converted to a ToolResult::error(). Tools that need
 * custom execute() behaviour (e.g. streaming, timeout watchdogs) can override
 * execute() directly while still inheriting the requiredParameters() default.
 */
abstract class AbstractTool implements ToolInterface
{
    /**
     * Perform the tool's work and return a result.
     *
     * @param  array<string, mixed>  $args  Named parameters matching parameters()
     */
    abstract protected function handle(array $args): ToolResult;

    /**
     * Delegates to handle() with automatic exception-to-error conversion.
     *
     * Override in subclasses that need custom execution flow (streaming, timeouts, etc.).
     */
    public function execute(array $args): ToolResult
    {
        try {
            return $this->handle($args);
        } catch (\Throwable $e) {
            return ToolResult::error(ErrorSanitizer::sanitize($e->getMessage()));
        }
    }

    /**
     * Defaults to all parameter keys. Override when only a subset is required.
     *
     * @return string[]
     */
    public function requiredParameters(): array
    {
        return array_keys($this->parameters());
    }
}
