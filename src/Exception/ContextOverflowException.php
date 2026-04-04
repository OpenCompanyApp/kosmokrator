<?php

declare(strict_types=1);

namespace Kosmokrator\Exception;

/**
 * Thrown when the LLM context window is exceeded.
 */
class ContextOverflowException extends KosmokratorException {}
