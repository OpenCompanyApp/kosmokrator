<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\CoreRendererInterface;
use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Theme;

/**
 * ANSI fallback implementation of core lifecycle and display methods.
 *
 * Handles initialization, intro, prompt, streaming, status bar, phase transitions,
 * and teardown for the pure-ANSI renderer.
 */
final class AnsiCoreRenderer implements CoreRendererInterface
{
    private readonly AnsiIntro $intro;

    private string $streamBuffer = '';

    private ?MarkdownToAnsi $markdownRenderer = null;

    private ?TaskStore $taskStore = null;

    private string $currentModeLabel = 'Edit';

    private string $currentPermissionLabel = 'Guardian ◈';

    private bool $wasActive = false;

    private ?int $lastStatusTokensIn = null;

    private ?float $lastStatusCost = null;

    private ?int $lastStatusMaxContext = null;

    /** @var array<array{question: string, answer: string, answered: bool, recommended: bool}> */
    private array $pendingQuestionRecap = [];

    /** @var \Closure(): void */
    private \Closure $flushQuestionRecapCallback;

    public function __construct(\Closure $flushQuestionRecapCallback)
    {
        $this->intro = new AnsiIntro;
        $this->flushQuestionRecapCallback = $flushQuestionRecapCallback;
    }

    public function setTaskStore(TaskStore $store): void
    {
        $this->taskStore = $store;
    }

    /** No-op: ANSI mode re-renders the task bar on each prompt() call. */
    public function refreshTaskBar(): void
    {
        // ANSI: task bar is rendered fresh on each prompt() call, no explicit refresh needed
    }

    /** No-op: ANSI mode has no widget tree to initialize. */
    public function initialize(): void
    {
        // Nothing needed for ANSI mode
    }

    public function renderIntro(bool $animated): void
    {
        if (getenv('KOSMOKRATOR_NO_ANIM') === '1') {
            $this->intro->renderStatic();
        } elseif ($animated) {
            $this->intro->animate();
        } else {
            $this->intro->renderStatic();
        }
    }

    public function prompt(): string
    {
        ($this->flushQuestionRecapCallback)();
        $this->echoTaskBar();

        $r = Theme::reset();
        $red = Theme::primary();

        $input = readline($red.'  ⟡ '.$r);

        if ($input === false) {
            return '/quit';
        }

        return trim($input);
    }

    /** No-op: readline already echoes the typed input to stdout. */
    public function showUserMessage(string $text): void
    {
        ($this->flushQuestionRecapCallback)();
        // No-op: readline already displays the typed input
    }

    public function setPhase(AgentPhase $phase): void
    {
        if ($phase === AgentPhase::Thinking || $phase === AgentPhase::Tools) {
            $this->wasActive = true;
        }

        if ($phase === AgentPhase::Thinking) {
            $this->showThinking();
        }

        if ($phase === AgentPhase::Idle && $this->wasActive) {
            $this->wasActive = false;
            TerminalNotification::notify();
        }
    }

    public function showThinking(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $blue = Theme::rgb(112, 160, 208);

        echo "\n{$dim}  ┌ {$blue}⚡ Thinking...{$r}\n";
    }

    /** No-op: ANSI thinking indicator is static text. */
    public function clearThinking(): void
    {
        // No-op for ANSI — thinking indicator is static text
    }

    public function showCompacting(): void
    {
        $r = Theme::reset();
        $red = Theme::rgb(208, 64, 64);
        echo "\n{$red}  ⧫ Compacting context...{$r}\n";
    }

    /** No-op: ANSI compacting indicator is static text. */
    public function clearCompacting(): void
    {
        // No-op for ANSI — static text
    }

    /** Always null — ANSI mode has no async cancellation support. */
    public function getCancellation(): ?Cancellation
    {
        return null;
    }

    public function showReasoningContent(string $content): void
    {
        ($this->flushQuestionRecapCallback)();
        $dim = Theme::dim();
        $r = Theme::reset();
        $border = Theme::borderTask();

        $lines = explode("\n", $content);
        $truncated = count($lines) > 10;
        $preview = implode("\n", array_slice($lines, 0, 10));

        echo "\n\n{$dim}{$border}⟐ Reasoning{$r}\n";
        echo "{$dim}{$preview}{$r}\n";
        if ($truncated) {
            echo "{$dim}  ... +".(count($lines) - 10)." lines{$r}\n";
        }
    }

    public function streamChunk(string $text): void
    {
        ($this->flushQuestionRecapCallback)();
        $this->streamBuffer .= $text;
    }

    public function streamComplete(): void
    {
        if ($this->streamBuffer !== '') {
            if (str_contains($this->streamBuffer, "\x1b[")) {
                // Raw ANSI art — output directly, don't parse as markdown
                echo "\n".$this->streamBuffer.Theme::reset()."\n";
            } else {
                $rendered = $this->getMarkdownRenderer()->render($this->streamBuffer);
                echo "\n".$rendered;
            }
            $this->streamBuffer = '';
        }
    }

    public function showError(string $message): void
    {
        ($this->flushQuestionRecapCallback)();
        $r = Theme::reset();
        $err = Theme::error();
        echo "\n{$err}  ✗ Error: {$message}{$r}\n\n";
    }

    public function showNotice(string $message): void
    {
        ($this->flushQuestionRecapCallback)();
        $r = Theme::reset();
        $yellow = Theme::warning();
        echo "\n{$yellow}  {$message}{$r}\n\n";
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->currentModeLabel = $label;
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->currentPermissionLabel = $label;
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        ($this->flushQuestionRecapCallback)();
        $this->lastStatusTokensIn = $tokensIn;
        $this->lastStatusCost = $cost;
        $this->lastStatusMaxContext = $maxContext;
        $r = Theme::reset();
        $dim = Theme::dim();
        $bar = Theme::contextBar($tokensIn, $maxContext);
        $costLabel = Theme::formatCost($cost);

        $permPart = in_array($this->currentModeLabel, ['Plan', 'Ask'])
            ? '' : " {$dim}{$this->currentPermissionLabel} ·";

        echo "{$dim}  {$this->currentModeLabel} ·{$permPart} {$model} · {$bar} {$dim}· {$costLabel}{$r}\n\n";
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $label = $provider.'/'.$model;

        if ($this->lastStatusMaxContext !== null) {
            $this->showStatus(
                $label,
                min($this->lastStatusTokensIn ?? 0, $maxContext),
                0,
                $this->lastStatusCost ?? 0.0,
                $maxContext,
            );

            return;
        }

        $r = Theme::reset();
        $dim = Theme::dim();
        echo "{$dim}  Active model: {$label}{$r}\n\n";
    }

    /** Always null — ANSI mode is synchronous, no message queue. */
    public function consumeQueuedMessage(): ?string
    {
        return null;
    }

    /** No-op: ANSI mode is synchronous, immediate commands not supported. */
    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        // No-op: ANSI mode is synchronous, immediate commands not supported
    }

    public function teardown(): void
    {
        echo Theme::showCursor();
    }

    public function showWelcome(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $text = Theme::text();
        $white = Theme::white();
        $gold = Theme::accent();
        $border = Theme::primaryDim();
        $orbit = Theme::rgb(60, 50, 70);
        $sun = Theme::rgb(255, 220, 80);
        $mercury = Theme::rgb(180, 180, 200);
        $venus = Theme::rgb(255, 180, 100);
        $earth = Theme::rgb(80, 160, 255);
        $mars = Theme::rgb(255, 80, 60);
        $jupiter = Theme::rgb(255, 200, 130);
        $saturn = Theme::rgb(210, 180, 140);
        $uranus = Theme::rgb(130, 210, 230);
        $neptune = Theme::rgb(70, 100, 220);
        $ring = Theme::rgb(80, 70, 90);
        $ringDim = Theme::rgb(50, 45, 60);

        // Orrery — concentric planetary orbits
        echo "\n";
        echo "                    {$ringDim}·  ·  ·  {$uranus}♅{$r}  {$ringDim}·  ·  ·{$r}\n";
        echo "                {$orbit}·{$r}        {$ring}·{$r} {$earth}♁{$r} {$ring}·{$r}        {$orbit}·{$r}\n";
        echo "             {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$mercury}☿{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}\n";
        echo "           {$saturn}♄{$r}   {$ring}·{$r}         {$sun}☉{$r}         {$ring}·{$r}   {$jupiter}♃{$r}\n";
        echo "             {$orbit}·{$r}     {$ring}·{$r}    {$ring}·{$venus}♀{$ring}·{$r}    {$ring}·{$r}     {$orbit}·{$r}\n";
        echo "                {$orbit}·{$r}        {$ring}·{$r} {$mars}♂{$r} {$ring}·{$r}        {$orbit}·{$r}\n";
        echo "                    {$ringDim}·  ·  ·  {$neptune}♆{$r}  {$ringDim}·  ·  ·{$r}\n";
        echo "\n";

        $green = Theme::rgb(80, 200, 120);
        $purple = Theme::rgb(160, 120, 255);
        $orange = Theme::rgb(255, 180, 60);
        $silver = Theme::rgb(180, 180, 200);
        $steel = Theme::rgb(100, 140, 200);
        $cyan = Theme::rgb(100, 200, 200);

        // Quick reference
        echo "  {$gold}Quick Reference{$r}\n";
        echo "  {$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n";
        echo "  {$green}/edit{$dim}  {$purple}/plan{$dim}  {$orange}/ask{$r}               {$dim}Agent mode (write / read-only / Q&A){$r}\n";
        echo "  {$silver}/guardian{$dim}  {$steel}/argus{$dim}  {$gold}/prometheus{$r}    {$dim}Permission mode (smart / strict / auto){$r}\n";
        echo "  {$cyan}/compact{$dim}  {$cyan}/new{$dim}  {$cyan}/resume{$dim}  {$cyan}/tasks clear{$r}  {$dim}Context and session management{$r}\n";
        $muted = Theme::rgb(160, 160, 170);
        echo "  {$muted}/settings{$dim}  {$muted}/memories{$dim}  {$muted}/sessions{$dim}  {$muted}/agents{$r}  {$dim}Configuration and monitoring{$r}\n";
        echo "  {$border}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n";
        echo "\n";
        echo "  {$text}Type a message to begin. Press {$white}Ctrl+C{$text} to exit.{$r}\n\n";
    }

    public function playTheogony(): void
    {
        $this->playAnimation(new AnsiTheogony);
    }

    public function playPrometheus(): void
    {
        $this->playAnimation(new AnsiPrometheus);
    }

    public function playUnleash(): void
    {
        $this->playAnimation(new AnsiUnleash);
    }

    public function playAnimation(AnsiAnimation $animation): void
    {
        $animation->animate();
    }

    public function setSkillCompletions(array $completions): void {}

    /** Returns the current mode label for use by other sub-renderers. */
    public function getCurrentModeLabel(): string
    {
        return $this->currentModeLabel;
    }

    /** Returns the current permission label for use by other sub-renderers. */
    public function getCurrentPermissionLabel(): string
    {
        return $this->currentPermissionLabel;
    }

    private function getMarkdownRenderer(): MarkdownToAnsi
    {
        return $this->markdownRenderer ??= new MarkdownToAnsi;
    }

    /** Prints the sticky task bar from the TaskStore before each prompt. */
    private function echoTaskBar(): void
    {
        if ($this->taskStore === null || $this->taskStore->isEmpty()) {
            return;
        }

        $r = Theme::reset();
        $border = Theme::borderTask();
        $accent = Theme::accent();

        $tree = $this->taskStore->renderAnsiTree();
        $lines = explode("\n", $tree);

        echo "{$border}  ┌ {$accent}Tasks{$r}\n";
        foreach ($lines as $line) {
            echo "{$border}  │{$r} {$line}{$r}\n";
        }
        echo "{$border}  └{$r}\n";
    }
}
