<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Ansi;

use Amp\Cancellation;
use Kosmokrator\Agent\AgentPhase;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\UI\RendererInterface;
use Kosmokrator\UI\Theme;

/**
 * ANSI fallback renderer for Kosmokrator's dual TUI/ANSI rendering layer.
 *
 * Thin coordinator that delegates to sub-renderers aligned with the sub-interface
 * boundaries: AnsiCoreRenderer, AnsiToolRenderer, AnsiDialogRenderer,
 * AnsiConversationRenderer, and AnsiSubagentRenderer.
 */
class AnsiRenderer implements RendererInterface
{
    private AnsiCoreRenderer $core;

    private AnsiToolRenderer $tool;

    private AnsiDialogRenderer $dialog;

    private AnsiConversationRenderer $conversation;

    private AnsiSubagentRenderer $subagent;

    /** @var array<array{question: string, answer: string, answered: bool, recommended: bool}> */
    private array $pendingQuestionRecap = [];

    public function __construct()
    {
        $flushCallback = $this->flushPendingQuestionRecap(...);
        $clearCallback = fn () => $this->pendingQuestionRecap = [];
        $queueCallback = fn (string $question, string $answer, bool $answered, bool $recommended) => $this->queueQuestionRecap($question, $answer, $answered, $recommended);

        $this->core = new AnsiCoreRenderer($flushCallback);
        $this->tool = new AnsiToolRenderer($flushCallback);
        $this->dialog = new AnsiDialogRenderer($queueCallback);
        $this->conversation = new AnsiConversationRenderer(
            $this->tool,
            $flushCallback,
            $clearCallback,
            $queueCallback,
        );
        $this->subagent = new AnsiSubagentRenderer;
    }

    // ── CoreRendererInterface ───────────────────────────────────────────

    public function setTaskStore(TaskStore $store): void
    {
        $this->core->setTaskStore($store);
        $this->tool->setTaskStore($store);
    }

    public function refreshTaskBar(): void
    {
        $this->core->refreshTaskBar();
    }

    public function initialize(): void
    {
        $this->core->initialize();
    }

    public function renderIntro(bool $animated): void
    {
        $this->core->renderIntro($animated);
    }

    public function prompt(): string
    {
        return $this->core->prompt();
    }

    public function showUserMessage(string $text): void
    {
        $this->core->showUserMessage($text);
    }

    public function setPhase(AgentPhase $phase): void
    {
        $this->core->setPhase($phase);
    }

    public function showThinking(): void
    {
        $this->core->showThinking();
    }

    public function clearThinking(): void
    {
        $this->core->clearThinking();
    }

    public function showCompacting(): void
    {
        $this->core->showCompacting();
    }

    public function clearCompacting(): void
    {
        $this->core->clearCompacting();
    }

    public function getCancellation(): ?Cancellation
    {
        return $this->core->getCancellation();
    }

    public function showReasoningContent(string $content): void
    {
        $this->core->showReasoningContent($content);
    }

    public function streamChunk(string $text): void
    {
        $this->core->streamChunk($text);
    }

    public function streamComplete(): void
    {
        $this->core->streamComplete();
    }

    public function showError(string $message): void
    {
        $this->core->showError($message);
    }

    public function showNotice(string $message): void
    {
        $this->core->showNotice($message);
    }

    public function showMode(string $label, string $color = ''): void
    {
        $this->core->showMode($label, $color);
    }

    public function setPermissionMode(string $label, string $color): void
    {
        $this->core->setPermissionMode($label, $color);
    }

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost, int $maxContext): void
    {
        $this->core->showStatus($model, $tokensIn, $tokensOut, $cost, $maxContext);
    }

    public function refreshRuntimeSelection(string $provider, string $model, int $maxContext): void
    {
        $this->core->refreshRuntimeSelection($provider, $model, $maxContext);
    }

    public function consumeQueuedMessage(): ?string
    {
        return $this->core->consumeQueuedMessage();
    }

    public function setImmediateCommandHandler(?\Closure $handler): void
    {
        $this->core->setImmediateCommandHandler($handler);
    }

    public function teardown(): void
    {
        $this->core->teardown();
    }

    public function showWelcome(): void
    {
        $this->core->showWelcome();
    }

    public function playTheogony(): void
    {
        $this->core->playTheogony();
    }

    public function playPrometheus(): void
    {
        $this->core->playPrometheus();
    }

    public function playUnleash(): void
    {
        $this->core->playUnleash();
    }

    public function playAnimation(AnsiAnimation $animation): void
    {
        $this->core->playAnimation($animation);
    }

    public function setSkillCompletions(array $completions): void {}

    // ── ToolRendererInterface ───────────────────────────────────────────

    public function showToolCall(string $name, array $args): void
    {
        $this->tool->showToolCall($name, $args);
    }

    public function showToolResult(string $name, string $output, bool $success): void
    {
        $this->tool->showToolResult($name, $output, $success);
    }

    public function askToolPermission(string $toolName, array $args): string
    {
        return $this->tool->askToolPermission($toolName, $args);
    }

    public function showAutoApproveIndicator(string $toolName): void
    {
        $this->tool->showAutoApproveIndicator($toolName);
    }

    public function showToolExecuting(string $name): void
    {
        $this->tool->showToolExecuting($name);
    }

    public function updateToolExecuting(string $output): void
    {
        $this->tool->updateToolExecuting($output);
    }

    public function clearToolExecuting(): void
    {
        $this->tool->clearToolExecuting();
    }

    // ── DialogRendererInterface ─────────────────────────────────────────

    public function showSettings(array $currentSettings): array
    {
        return $this->dialog->showSettings($currentSettings);
    }

    public function pickSession(array $items): ?string
    {
        return $this->dialog->pickSession($items);
    }

    public function approvePlan(string $currentPermissionMode): ?array
    {
        return $this->dialog->approvePlan($currentPermissionMode);
    }

    public function askUser(string $question): string
    {
        return $this->dialog->askUser($question);
    }

    public function askChoice(string $question, array $choices): string
    {
        return $this->dialog->askChoice($question, $choices);
    }

    // ── ConversationRendererInterface ───────────────────────────────────

    public function clearConversation(): void
    {
        $this->conversation->clearConversation();
    }

    public function replayHistory(array $messages): void
    {
        $this->conversation->replayHistory($messages);
    }

    // ── SubagentRendererInterface ───────────────────────────────────────

    public function showSubagentStatus(array $stats): void
    {
        $this->subagent->showSubagentStatus($stats);
    }

    public function clearSubagentStatus(): void
    {
        $this->subagent->clearSubagentStatus();
    }

    public function showSubagentRunning(array $entries): void
    {
        $this->subagent->showSubagentRunning($entries);
    }

    public function showSubagentSpawn(array $entries): void
    {
        $this->subagent->showSubagentSpawn($entries);
    }

    public function showSubagentBatch(array $entries): void
    {
        $this->subagent->showSubagentBatch($entries);
    }

    public function refreshSubagentTree(array $tree): void
    {
        $this->subagent->refreshSubagentTree($tree);
    }

    public function setAgentTreeProvider(?\Closure $provider): void
    {
        $this->subagent->setAgentTreeProvider($provider);
    }

    public function showAgentsDashboard(array $summary, array $allStats, ?\Closure $refresh = null): void
    {
        $this->subagent->showAgentsDashboard($summary, $allStats, $refresh);
    }

    // ── Non-interface methods ───────────────────────────────────────────

    /** Plays a scripted demo session with typewriter-style output for showcase purposes. */
    public function seedMockSession(): void
    {
        $r = Theme::reset();
        $dim = Theme::dim();
        $gray = Theme::text();
        $white = Theme::white();
        $red = Theme::primary();
        $green = Theme::success();
        $yellow = Theme::warning();
        $cyan = Theme::info();
        $blue = Theme::link();
        $magenta = Theme::code();
        $dimGreen = Theme::diffAdd();
        $dimRed = Theme::diffRemove();
        $bold = Theme::bold();
        $dimBg = Theme::codeBg();

        $steps = [
            fn () => $this->typeOut(
                "\n{$red}  ⟡ {$white}Refactor the UserService to use repository pattern and add caching{$r}\n",
                12000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n".
                "{$dim}  │{$r} Analyzing the codebase to understand the current UserService\n".
                "{$dim}  │{$r} implementation, identify dependencies, and plan the refactor.\n".
                "{$dim}  └ {$dim}(2.1s){$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Search{$r} {$dim}── finding relevant files{$r}\n".
                "{$dim}  │{$r} {$dim}Pattern:{$r} class UserService\n".
                "{$dim}  │{$r} {$dim}Found 3 matches:{$r}\n".
                "{$dim}  │{$r}   {$blue}app/Services/UserService.php{$r}{$dim}:12{$r}  — class UserService\n".
                "{$dim}  │{$r}   {$blue}app/Http/Controllers/UserController.php{$r}{$dim}:8{$r}  — use UserService\n".
                "{$dim}  │{$r}   {$blue}tests/Unit/UserServiceTest.php{$r}{$dim}:15{$r}  — class UserServiceTest\n".
                "{$dim}  └ {$dim}(0.3s){$r}\n",
                6000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Read{$r} {$blue}app/Services/UserService.php{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$gray} 1{$r}  {$dimBg} <?php{$r}\n".
                "{$dim}  │  {$gray} 2{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 3{$r}  {$dimBg} {$magenta}namespace{$r}{$dimBg} App\\Services;{$r}\n".
                "{$dim}  │  {$gray} 4{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} App\\Models\\User;{$r}\n".
                "{$dim}  │  {$gray} 6{$r}  {$dimBg} {$magenta}use{$r}{$dimBg} Illuminate\\Support\\Facades\\DB;{$r}\n".
                "{$dim}  │  {$gray} 7{$r}  {$dimBg} {$r}\n".
                "{$dim}  │  {$gray} 8{$r}  {$dimBg} {$magenta}class{$r}{$dimBg} {$yellow}UserService{$r}\n".
                "{$dim}  │  {$gray} 9{$r}  {$dimBg} {{$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimBg}     {$magenta}public function{$r}{$dimBg} {$cyan}getById{$r}{$dimBg}({$magenta}int{$r}{$dimBg} \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}11{$r}  {$dimBg}     {{$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimBg}         {$magenta}return{$r}{$dimBg} User::find(\$id);{$r}\n".
                "{$dim}  │  {$gray}13{$r}  {$dimBg}     }{$r}\n".
                "{$dim}  │  {$gray}14{$r}  {$dimBg} }{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$dim}14 lines{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}⚡ Thinking...{$r}\n".
                "{$dim}  │{$r} The service directly queries Eloquent. I'll extract a\n".
                "{$dim}  │{$r} UserRepositoryInterface, create an EloquentUserRepository,\n".
                "{$dim}  │{$r} and add a caching decorator using Laravel's Cache facade.\n".
                "{$dim}  └ {$dim}(1.8s){$r}\n",
                8000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$green}◈ Write{$r} {$blue}app/Repositories/UserRepositoryInterface.php{$r} {$dim}(new){$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$dimGreen}+ <?php{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ namespace App\\Repositories;{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ use App\\Models\\User;{$r}\n".
                "{$dim}  │  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$dimGreen}+ interface UserRepositoryInterface{$r}\n".
                "{$dim}  │  {$dimGreen}+ {{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function find(int \$id): ?User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function findByEmail(string \$email): ?User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function save(User \$user): User;{$r}\n".
                "{$dim}  │  {$dimGreen}+     public function delete(int \$id): bool;{$r}\n".
                "{$dim}  │  {$dimGreen}+ }{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Created{$r}\n",
                4000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$yellow}◈ Edit{$r} {$blue}app/Services/UserService.php{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimRed}- use Illuminate\\Support\\Facades\\DB;{$r}\n".
                "{$dim}  │  {$gray} 5{$r}  {$dimGreen}+ use App\\Repositories\\UserRepositoryInterface;{$r}\n".
                "{$dim}  │  {$gray} 6{$r}  {$dimGreen}+ use Illuminate\\Support\\Facades\\Cache;{$r}\n".
                "{$dim}  │  {$gray}  {$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimRed}-     public function getById(int \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}10{$r}  {$dimGreen}+     public function __construct({$r}\n".
                "{$dim}  │  {$gray}11{$r}  {$dimGreen}+         private UserRepositoryInterface \$repository{$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimGreen}+     ) {}{$r}\n".
                "{$dim}  │  {$gray}13{$r}  {$dimGreen}+{$r}\n".
                "{$dim}  │  {$gray}14{$r}  {$dimGreen}+     public function getById(int \$id): ?User{$r}\n".
                "{$dim}  │  {$gray}  {$r}\n".
                "{$dim}  │  {$gray}12{$r}  {$dimRed}-         return User::find(\$id);{$r}\n".
                "{$dim}  │  {$gray}16{$r}  {$dimGreen}+         return Cache::remember(\"user.{\$id}\", 3600, function () use (\$id) {{$r}\n".
                "{$dim}  │  {$gray}17{$r}  {$dimGreen}+             return \$this->repository->find(\$id);{$r}\n".
                "{$dim}  │  {$gray}18{$r}  {$dimGreen}+         });{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Saved{$r} {$dim}(-2, +9 lines){$r}\n",
                3000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ┌ {$cyan}◈ Bash{$r} {$dim}php artisan test --filter=UserService{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │{$r}   {$green}PASS{$r}  Tests\\Unit\\UserServiceTest\n".
                "{$dim}  │{$r}   {$green}✓{$r} it returns a user by id {$dim}(0.04s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it caches the user after first fetch {$dim}(0.02s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it invalidates cache on user update {$dim}(0.03s){$r}\n".
                "{$dim}  │{$r}   {$green}✓{$r} it delegates to repository for persistence {$dim}(0.01s){$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  │{$r}   Tests:    {$bold}{$green}4 passed{$r} {$dim}(4 assertions){$r}\n".
                "{$dim}  │{$r}   Duration: {$dim}0.31s{$r}\n".
                "{$dim}  │{$r}\n".
                "{$dim}  └ {$green}✓ Exit code 0{$r}\n",
                5000
            ),
            fn () => $this->typeOut(
                "\n{$dim}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━{$r}\n\n".
                "  {$white}Done.{$r} Refactored UserService to repository pattern with caching.\n\n".
                "  {$dim}Files changed:{$r}\n".
                "    {$green}+{$r} app/Repositories/UserRepositoryInterface.php {$dim}(new){$r}\n".
                "    {$green}+{$r} app/Repositories/EloquentUserRepository.php {$dim}(new){$r}\n".
                "    {$yellow}~{$r} app/Services/UserService.php {$dim}(-2, +9){$r}\n".
                "    {$yellow}~{$r} app/Providers/AppServiceProvider.php {$dim}(+3){$r}\n\n".
                "  {$dim}Tokens: 1,847 in · 923 out · cost: \$0.024{$r}\n\n",
                6000
            ),
        ];

        foreach ($steps as $step) {
            $step();
            usleep(300000);
        }
    }

    // ── Shared state: question recap ────────────────────────────────────

    /** Queues a question/answer pair for deferred display before the next output. */
    private function queueQuestionRecap(string $question, string $answer, bool $answered, bool $recommended = false): void
    {
        $this->pendingQuestionRecap[] = [
            'question' => $question,
            'answer' => $answer,
            'answered' => $answered,
            'recommended' => $answered && $recommended,
        ];
    }

    /** Flushes all queued question/answer pairs as a formatted block before the next output. */
    private function flushPendingQuestionRecap(): void
    {
        $this->tool->finalizeDiscoveryBatch();

        if ($this->pendingQuestionRecap === []) {
            return;
        }

        $r = Theme::reset();
        $accent = Theme::accent();
        $white = Theme::white();
        $answerColor = Theme::info();
        $dim = Theme::dim();

        $answeredCount = count(array_filter($this->pendingQuestionRecap, static fn (array $entry): bool => $entry['answered']));
        echo "\n{$accent}› •{$r} {$dim}Questions {$answeredCount}/".count($this->pendingQuestionRecap)." answered{$r}\n";

        foreach ($this->pendingQuestionRecap as $index => $entry) {
            if ($index > 0) {
                echo "\n";
            }

            foreach ($this->wrapWithPrefix($entry['question'], '    • ', '      ', 100) as $line) {
                echo "{$white}{$line}{$r}\n";
            }

            $answer = $entry['answered']
                ? $entry['answer'].($entry['recommended'] ? ' (Recommended)' : '')
                : '(dismissed)';
            $color = $entry['answered'] ? $answerColor : $dim;

            foreach ($this->wrapWithPrefix($answer, '      ', '      ', 100) as $line) {
                echo "{$color}{$line}{$r}\n";
            }
        }

        $this->pendingQuestionRecap = [];
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /** @return string[] */
    private function wrapWithPrefix(string $text, string $firstPrefix, string $restPrefix, int $width): array
    {
        $wrapped = [];
        $current = '';
        $words = preg_split('/\s+/', trim($text)) ?: [];

        foreach ($words as $word) {
            $prefix = $current === '' && $wrapped === [] ? $firstPrefix : ($current === '' ? $restPrefix : '');
            $lineWidth = max(10, $width - mb_strwidth($prefix));
            $candidate = $current === '' ? $word : "{$current} {$word}";

            if (mb_strwidth($candidate) > $lineWidth) {
                if ($current !== '') {
                    $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;
                    $current = $word;

                    continue;
                }

                $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).mb_substr($word, 0, $lineWidth);
                $current = mb_substr($word, $lineWidth);

                continue;
            }

            $current = $candidate;
        }

        if ($current === '') {
            return [($wrapped === [] ? $firstPrefix : $restPrefix)];
        }

        $wrapped[] = ($wrapped === [] ? $firstPrefix : $restPrefix).$current;

        return $wrapped;
    }

    private function typeOut(string $text, int $charDelay): void
    {
        foreach (mb_str_split($text) as $char) {
            echo $char;
            if ($char !== "\n" && $char !== ' ') {
                usleep($charDelay);
            }
        }
    }
}
