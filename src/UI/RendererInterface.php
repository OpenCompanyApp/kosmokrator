<?php

declare(strict_types=1);

namespace Kosmokrator\UI;

/**
 * Composite renderer interface aggregating all UI concerns.
 *
 * Combines core lifecycle, tool rendering, dialog interaction,
 * conversation replay, and subagent orchestration into a single contract.
 * Implemented by UIManager, AnsiRenderer, TuiRenderer, and NullRenderer.
 */
interface RendererInterface extends ConversationRendererInterface, CoreRendererInterface, DialogRendererInterface, SubagentRendererInterface, ToolRendererInterface {}
