<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui;

use Kosmokrator\LLM\ToolCallMapper;
use Kosmokrator\UI\Ansi\KosmokratorTerminalTheme;
use Kosmokrator\UI\Diff\DiffRenderer;
use Kosmokrator\UI\Highlight\Lua\LuaLanguage;
use Kosmokrator\UI\Theme;
use Kosmokrator\UI\ToolRendererInterface;
use Kosmokrator\UI\Tui\Builder\ToolExecutionCard;
use Kosmokrator\UI\Tui\State\TuiStateStore;
use Kosmokrator\UI\Tui\Widget\BashCommandWidget;
use Kosmokrator\UI\Tui\Widget\CollapsibleWidget;
use Kosmokrator\UI\Tui\Widget\DiscoveryBatchWidget;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Widget\TextWidget;
use Tempest\Highlight\Highlighter;

/**
 * TUI implementation of tool call/result display and permission prompts.
 *
 * Manages tool call widgets, result rendering (including diffs and syntax highlighting),
 * executing spinners, discovery batch grouping, and bash command widgets.
 */
final class TuiToolRenderer implements ToolRendererInterface
{
    private ?DiffRenderer $diffRenderer = null;

    private ?Highlighter $highlighter = null;

    private ?ToolExecutionCard $toolExecutionCard = null;

    private ?DiscoveryBatchWidget $activeDiscoveryBatch = null;

    private ?BashCommandWidget $activeBashWidget = null;

    /** @var array<string, mixed> */
    private array $lastToolArgs = [];

    /** @var array<string, array<string, mixed>> */
    private array $lastToolArgsByName = [];

    /** @var list<array{name: string, label: string, detail: string, summary: string, status: string}> */
    private array $activeDiscoveryItems = [];

    public function __construct(
        private readonly TuiCoreRenderer $core,
        private readonly TuiStateStore $state,
        private readonly LoggerInterface $log = new NullLogger,
    ) {}

    private function toolExecutionCard(): ToolExecutionCard
    {
        if ($this->toolExecutionCard === null) {
            $this->toolExecutionCard = new ToolExecutionCard(
                state: $this->state,
                conversation: $this->core->getConversation(),
                addConversationWidget: fn ($w) => $this->core->addConversationWidget($w),
            );
        }

        return $this->toolExecutionCard;
    }

    public function getLastToolArgs(): array
    {
        return $this->lastToolArgs;
    }

    public function setLastToolArgs(array $args): void
    {
        $this->lastToolArgs = $args;
    }

    public function getActiveBashWidget(): ?BashCommandWidget
    {
        return $this->activeBashWidget;
    }

    public function resetActiveBashWidget(): void
    {
        $this->activeBashWidget = null;
    }

    public function showToolCall(string $name, array $args): void
    {
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->core->flushPendingQuestionRecap();
        }

        $this->lastToolArgs = $args;
        $this->lastToolArgsByName[$name] = $args;
        $icon = Theme::toolIcon($name);
        $friendly = Theme::toolLabel($name);
        $r = Theme::reset();
        $dim = Theme::dim();
        $white = Theme::white();

        // Task tools: update task bar only
        if ($this->isTaskTool($name)) {
            $this->finalizeDiscoveryBatch();
            $this->core->refreshTaskBar();

            return;
        }

        // Ask tools: silent
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->finalizeDiscoveryBatch();

            return;
        }

        // Subagent: handled by showSubagentSpawn/showSubagentBatch
        if ($name === 'subagent') {
            $this->finalizeDiscoveryBatch();

            return;
        }

        // Lua tools: show full code with syntax highlighting
        if ($name === 'execute_lua' && isset($args['code'])) {
            $this->finalizeDiscoveryBatch();
            $this->showLuaCodeCall($args['code']);

            return;
        }

        // Lua doc tools: compact inline
        if (in_array($name, ['lua_list_docs', 'lua_search_docs', 'lua_read_doc'], true)) {
            $this->finalizeDiscoveryBatch();
            $this->showLuaDocCall($name, $args);

            return;
        }

        if ($name === 'bash' && ! $this->isOmensTool($name, $args)) {
            $this->finalizeDiscoveryBatch();
            $this->beginBashCommand((string) ($args['command'] ?? ''));

            return;
        }

        if ($this->isOmensTool($name, $args)) {
            $this->appendDiscoveryToolCall($name, $args);
            $this->core->flushRender();

            return;
        }

        $this->finalizeDiscoveryBatch();

        // Compact single-line display for file tools
        if (in_array($name, ['file_read', 'file_write', 'file_edit']) && isset($args['path'])) {
            $path = Theme::relativePath($args['path']);
            $label = "{$icon} {$friendly}  {$path}";
            if (isset($args['offset'])) {
                $label .= ":{$args['offset']}";
            }
        } else {
            $skipKeys = ['content', 'old_string', 'new_string'];
            $parts = [];
            foreach ($args as $key => $value) {
                if (in_array($key, $skipKeys, true)) {
                    continue;
                }
                $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                $parts[] = "{$key}: {$display}";
            }
            $label = "{$icon} {$friendly}  ".implode('  ', $parts);
        }

        $maxToolCallWidth = 120;

        if (mb_strlen($label) > $maxToolCallWidth) {
            $header = "{$icon} {$friendly}";
            $argsStr = mb_substr($label, mb_strlen($header) + 2);
            $widget = new CollapsibleWidget($header, $argsStr, 1, $maxToolCallWidth);
            $widget->addStyleClass('tool-call');
        } else {
            $widget = new TextWidget($label);
            $widget->addStyleClass('tool-call');
        }

        $this->core->addConversationWidget($widget);
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        if (! in_array($name, ['ask_user', 'ask_choice'], true)) {
            $this->core->flushPendingQuestionRecap();
        }

        $statusColor = $success ? Theme::success() : Theme::error();
        $indicator = $success ? '✓' : '✗';
        $r = Theme::reset();
        $text = Theme::text();

        $header = "{$statusColor}{$indicator}{$r}";

        // Task tools: silent result
        if ($this->isTaskTool($name)) {
            $this->core->refreshTaskBar();

            return;
        }

        // Ask tools: silent result
        if (in_array($name, ['ask_user', 'ask_choice'], true)) {
            return;
        }

        // Subagent: handled by showSubagentBatch
        if ($name === 'subagent') {
            return;
        }

        $args = $this->lastToolArgsByName[$name] ?? $this->lastToolArgs;

        if ($name === 'bash' && ! $this->isOmensTool($name, $args)) {
            $this->completeBashCommand($output, $success);
            $this->core->flushRender();

            return;
        }

        if ($this->isOmensTool($name, $args)) {
            $this->completeDiscoveryToolResult($name, $output, $success);
            $this->core->flushRender();

            return;
        }

        // Lua execution result: show output collapsed by default
        if ($name === 'execute_lua') {
            $content = $this->highlightLuaOutput($output);
            $lineCount = count(explode("\n", $output));
            $widget = new CollapsibleWidget($header, $content, $lineCount);
            $widget->addStyleClass('tool-result');
            $this->core->addConversationWidget($widget);

            return;
        }

        // Lua doc tools: compact result
        if (in_array($name, ['lua_list_docs', 'lua_search_docs', 'lua_read_doc'], true)) {
            $content = implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
            $lineCount = count(explode("\n", $output));
            $widget = new CollapsibleWidget($header, $content, $lineCount);
            $widget->addStyleClass('tool-result');
            $this->core->addConversationWidget($widget);

            return;
        }

        // Diff view for file_edit
        if ($name === 'file_edit' && $success && isset($args['old_string'])) {
            $content = $this->buildDiffView(
                $args['old_string'],
                $args['new_string'] ?? '',
                $args['path'] ?? '',
            );
            $lineCount = count(explode("\n", $content));
        } elseif ($name === 'file_read' && $success) {
            $content = $this->highlightFileOutput($output);
            $lineCount = count(explode("\n", $output));
        } else {
            $content = implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
            $lineCount = count(explode("\n", $output));
        }

        $widget = new CollapsibleWidget($header, $content, $lineCount);
        $widget->addStyleClass('tool-result');
        if ($name === 'file_edit' && $success) {
            $widget->setExpanded(true);
        }
        $this->core->addConversationWidget($widget);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->core->getModalManager()->askToolPermission($toolName, $args);
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        // Intentionally silent — auto-approve is already visible in the status bar
    }

    public function showToolExecuting(string $name): void
    {
        if ($this->isTaskTool($name)
            || $name === 'bash'
            || $this->isOmensTool($name, [])
            || in_array($name, ['ask_user', 'ask_choice', 'subagent'], true)) {
            return;
        }

        $this->core->getAnimationManager()->ensureSpinnersRegistered();
        $this->toolExecutionCard()->start();
    }

    public function updateToolExecuting(string $output): void
    {
        $lines = explode("\n", trim($output));
        $last = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                $last = $trimmed;
                break;
            }
        }
        if ($last !== '') {
            $this->state->setToolExecutingPreview(mb_strlen($last) > 100 ? mb_substr($last, 0, 100).'…' : $last);
        }
    }

    public function clearToolExecuting(): void
    {
        $this->toolExecutionCard()->stop();
    }

    // ── Discovery batch methods (used by TuiConversationRenderer too) ───

    public function finalizeDiscoveryBatch(): void
    {
        $this->activeDiscoveryBatch = null;
        // Keep activeDiscoveryItems for potential batch resume
        $this->state->setActiveDiscoveryItems([]);
    }

    public function isOmensTool(string $name, array $args): bool
    {
        return ExplorationClassifier::isOmensTool($name, $args);
    }

    public function isTaskTool(string $name): bool
    {
        return in_array($name, ['task_create', 'task_update', 'task_list', 'task_get'], true);
    }

    public function highlightFileOutput(string $output, ?string $path = null): string
    {
        $path ??= $this->lastToolArgs['path'] ?? '';
        $language = KosmokratorTerminalTheme::detectLanguage($path);
        if ($language === '') {
            return $output;
        }

        $lines = explode("\n", $output);
        $lineNums = [];
        $codeLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*\d+)\t(.*)$/', $line, $m)) {
                $lineNums[] = $m[1];
                $codeLines[] = $m[2];
            } else {
                $lineNums[] = null;
                $codeLines[] = $line;
            }
        }

        $code = implode("\n", $codeLines);
        try {
            $highlighted = $this->getHighlighter()->parse($code, $language);
        } catch (\Throwable $e) {
            $this->log->warning('Syntax highlight failed', ['error' => $e->getMessage(), 'language' => $language]);

            return $output;
        }

        $highlightedLines = explode("\n", $highlighted);
        $result = [];
        foreach ($highlightedLines as $i => $hLine) {
            if (isset($lineNums[$i]) && $lineNums[$i] !== null) {
                $result[] = Theme::dim()."{$lineNums[$i]}".Theme::reset()."\t{$hLine}";
            } else {
                $result[] = $hLine;
            }
        }

        return implode("\n", $result);
    }

    public function buildDiffView(string $old, string $new, string $path): string
    {
        return $this->getDiffRenderer()->render($old, $new, $path);
    }

    /**
     * @return array{name: string, label: string, detail: string, summary: string, status: 'pending'|'success'|'error'}
     */
    public function buildDiscoveryItem(
        string $name,
        array $args,
        string $output = '',
        bool $hasResult = false,
        bool $success = true,
    ): array {
        $label = match ($name) {
            'file_read' => $this->formatDiscoveryReadLabel($args),
            'glob' => $this->formatDiscoveryGlobLabel($args),
            'grep' => $this->formatDiscoveryGrepLabel($args),
            'bash' => $this->formatDiscoveryBashLabel($args),
            'memory_search' => $this->formatDiscoveryMemoryLabel($args),
            default => Theme::toolLabel($name),
        };

        if (! $hasResult) {
            return [
                'name' => $name,
                'label' => $label,
                'detail' => '',
                'summary' => '',
                'status' => 'pending',
            ];
        }

        return [
            'name' => $name,
            'label' => $label,
            'detail' => $name === 'file_read'
                ? $this->highlightFileOutput($output, (string) ($args['path'] ?? ''))
                : $output,
            'summary' => $this->summarizeDiscoveryResult($name, $output, $success),
            'status' => $success ? 'success' : 'error',
        ];
    }

    public function inferHistoricToolSuccess(string $name, mixed $toolResult): bool
    {
        if (! is_object($toolResult) || ! property_exists($toolResult, 'result')) {
            return true;
        }

        $result = $toolResult->result;
        if (! is_string($result)) {
            return true;
        }

        $trimmed = trim($result);
        if (str_starts_with($trimmed, ToolCallMapper::ERROR_PREFIX) || str_starts_with($trimmed, 'Error: ')) {
            return false;
        }

        if ($name === 'memory_search' && str_starts_with($trimmed, 'Invalid ')) {
            return false;
        }

        return true;
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private function appendDiscoveryToolCall(string $name, array $args): void
    {
        if ($this->activeDiscoveryBatch === null) {
            $lastWidget = $this->core->getLastConversationWidget();
            if ($lastWidget instanceof DiscoveryBatchWidget) {
                // Resume — no non-discovery widget was added since finalization
                $this->activeDiscoveryBatch = $lastWidget;
            } else {
                // Genuinely new batch
                $this->activeDiscoveryItems = [];
                $this->activeDiscoveryBatch = new DiscoveryBatchWidget;
                $this->activeDiscoveryBatch->addStyleClass('tool-batch');
                $this->core->addConversationWidget($this->activeDiscoveryBatch);
            }
        }

        $this->activeDiscoveryItems[] = $this->buildDiscoveryItem($name, $args);
        $this->activeDiscoveryBatch->setItems($this->activeDiscoveryItems);
    }

    private function completeDiscoveryToolResult(string $name, string $output, bool $success): void
    {
        if ($this->activeDiscoveryItems === []) {
            $this->appendDiscoveryToolCall($name, $this->lastToolArgs);
        }

        $lastIndex = array_key_last($this->activeDiscoveryItems);
        if ($lastIndex === null) {
            return;
        }

        $this->activeDiscoveryItems[$lastIndex] = $this->buildDiscoveryItem(
            $name,
            $this->lastToolArgs,
            $output,
            true,
            $success,
        );

        $this->activeDiscoveryBatch?->setItems($this->activeDiscoveryItems);
    }

    private function beginBashCommand(string $command): void
    {
        $this->activeBashWidget = new BashCommandWidget($command);
        $this->activeBashWidget->addStyleClass('tool-shell');
        $this->core->addConversationWidget($this->activeBashWidget);
    }

    private function completeBashCommand(string $output, bool $success): void
    {
        if ($this->activeBashWidget === null) {
            $this->beginBashCommand((string) ($this->lastToolArgs['command'] ?? ''));
        }

        $this->activeBashWidget?->setResult($output, $success);
        $this->activeBashWidget = null;
    }

    private function getDiffRenderer(): DiffRenderer
    {
        return $this->diffRenderer ??= new DiffRenderer($this->log);
    }

    private function getHighlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter(new KosmokratorTerminalTheme);
    }

    private function formatDiscoveryReadLabel(array $args): string
    {
        $path = Theme::relativePath((string) ($args['path'] ?? ''));

        if (isset($args['offset'])) {
            $path .= ':'.$args['offset'];
        }

        return $path;
    }

    private function formatDiscoveryGlobLabel(array $args): string
    {
        $pattern = (string) ($args['pattern'] ?? '');
        $path = $this->normalizeDiscoveryPath((string) ($args['path'] ?? ''));

        if ($path === '.' || $path === '') {
            return $pattern;
        }

        return "{$pattern} in {$path}";
    }

    private function formatDiscoveryGrepLabel(array $args): string
    {
        $pattern = '"'.(string) ($args['pattern'] ?? '').'"';
        $path = $this->normalizeDiscoveryPath((string) ($args['path'] ?? ''));
        $glob = (string) ($args['glob'] ?? '');

        $label = $path === '.' || $path === ''
            ? $pattern
            : "{$pattern} in {$path}";

        if ($glob !== '') {
            $label .= " ({$glob})";
        }

        return $label;
    }

    private function formatDiscoveryBashLabel(array $args): string
    {
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') {
            return 'shell probe';
        }

        return mb_strlen($command) > 90 ? mb_substr($command, 0, 90).'…' : $command;
    }

    private function formatDiscoveryMemoryLabel(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        $type = trim((string) ($args['type'] ?? ''));
        $memoryClass = trim((string) ($args['class'] ?? ''));
        $scope = trim((string) ($args['scope'] ?? ''));

        if ($query !== '') {
            return '"'.$query.'"'.($scope !== '' ? " in {$scope}" : '');
        }

        $parts = array_values(array_filter([
            $type !== '' ? $type : null,
            $memoryClass !== '' ? $memoryClass : null,
            $scope !== '' ? $scope : null,
        ]));

        return $parts === [] ? 'saved memories' : implode(' · ', $parts);
    }

    private function normalizeDiscoveryPath(string $path): string
    {
        if ($path === '' || $path === '.') {
            return '.';
        }

        return Theme::relativePath($path);
    }

    private function summarizeDiscoveryResult(string $name, string $output, bool $success): string
    {
        if (! $success) {
            return 'error';
        }

        return match ($name) {
            'file_read' => $this->countNonEmptyLines($output).' lines',
            'glob' => $this->summarizeCountedResult($output, 'file', 'files', 'No files matching'),
            'grep' => $this->summarizeCountedResult($output, 'match', 'matches', 'No matches found'),
            'bash' => $this->summarizeCountedResult($output, 'line', 'lines', 'No output'),
            'memory_search' => $this->summarizeMemorySearchResult($output),
            default => '',
        };
    }

    private function summarizeMemorySearchResult(string $output): string
    {
        $trimmed = trim($output);
        if ($trimmed === '' || $trimmed === 'No memories found.' || $trimmed === 'No session history matches found.') {
            return '0 recalls';
        }

        if (preg_match('/^Found (\d+) memories:/', $trimmed, $matches) === 1) {
            $count = (int) $matches[1];

            return $count.' '.($count === 1 ? 'recall' : 'recalls');
        }

        return $this->countNonEmptyLines($output).' lines';
    }

    private function summarizeCountedResult(string $output, string $singular, string $plural, string $emptyPrefix): string
    {
        $trimmed = trim($output);
        if ($trimmed === '' || str_starts_with($trimmed, $emptyPrefix)) {
            return "0 {$plural}";
        }

        $count = $this->countNonEmptyLines($output);

        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function countNonEmptyLines(string $output): int
    {
        return count(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    /**
     * Render an execute_lua tool call with full Lua code, syntax highlighted.
     */
    private function showLuaCodeCall(string $code): void
    {
        $icon = Theme::toolIcon('execute_lua');
        $friendly = Theme::toolLabel('execute_lua');
        $r = Theme::reset();
        $dim = Theme::dim();
        $gold = Theme::accent();

        $lineCount = count(explode("\n", $code));

        $header = "{$gold}{$icon} {$friendly}{$r}  {$dim}{$lineCount} lines{$r}";

        // Highlight Lua code with line numbers
        $highlighted = $this->highlightLuaCode($code);
        $highlightedLines = explode("\n", $highlighted);
        $padded = [];
        $numWidth = strlen((string) $lineCount);
        foreach ($highlightedLines as $i => $line) {
            $num = str_pad((string) ($i + 1), $numWidth, ' ', STR_PAD_LEFT);
            $padded[] = Theme::dim()."{$num}".Theme::reset()."\t{$line}";
        }

        $content = implode("\n", $padded);

        $widget = new CollapsibleWidget($header, $content, $lineCount);
        $widget->addStyleClass('tool-call');
        $widget->setExpanded(true);
        $this->core->addConversationWidget($widget);
    }

    /**
     * Render a Lua doc tool call (list/search/read) compactly.
     */
    private function showLuaDocCall(string $name, array $args): void
    {
        $icon = Theme::toolIcon($name);
        $friendly = Theme::toolLabel($name);
        $r = Theme::reset();
        $dim = Theme::dim();
        $gold = Theme::accent();

        $parts = [];
        foreach ($args as $key => $value) {
            $display = is_string($value) ? $value : json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
            $parts[] = "{$dim}{$key}:{$r} {$display}";
        }

        $label = "{$gold}{$icon} {$friendly}{$r}  ".implode("  {$dim}│{$r}  ", $parts);
        $widget = new TextWidget($label);
        $widget->addStyleClass('tool-call');
        $this->core->addConversationWidget($widget);
    }

    /**
     * Format Lua code for display with syntax highlighting.
     */
    private function highlightLuaCode(string $code): string
    {
        try {
            return $this->getHighlighter()->parse($code, new LuaLanguage);
        } catch (\Throwable $e) {
            $this->log->warning('Lua highlight failed', ['error' => $e->getMessage()]);

            $r = Theme::reset();
            $text = Theme::text();

            return implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $code)));
        }
    }

    /**
     * Format Lua execution output for display.
     */
    private function highlightLuaOutput(string $output): string
    {
        $r = Theme::reset();
        $text = Theme::text();

        return implode("\n", array_map(fn (string $l) => "{$text}{$l}{$r}", explode("\n", $output)));
    }
}
