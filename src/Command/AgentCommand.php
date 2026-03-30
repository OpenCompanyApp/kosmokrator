<?php

namespace Kosmokrator\Command;

use Illuminate\Container\Container;
use Kosmokrator\Agent\AgentLoop;
use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Agent\EnvironmentContext;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Agent\ContextCompactor;
use Kosmokrator\Agent\MemoryInjector;
use Kosmokrator\Task\TaskStore;
use Kosmokrator\LLM\AsyncLlmClient;
use Kosmokrator\LLM\LlmClientInterface;
use Kosmokrator\LLM\ModelCatalog;
use Kosmokrator\LLM\PrismService;
use Kosmokrator\Session\SessionManager;
use Kosmokrator\Tool\Permission\PermissionEvaluator;
use Kosmokrator\Tool\ToolRegistry;
use Kosmokrator\UI\UIManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent', description: 'Launch the KosmoKrator coding agent')]
class AgentCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('no-animation', null, InputOption::VALUE_NONE, 'Skip the intro animation');
        $this->addOption('renderer', null, InputOption::VALUE_REQUIRED, 'Force renderer (tui or ansi)', 'auto');
        $this->addOption('resume', null, InputOption::VALUE_NONE, 'Resume last session for this project');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'Resume a specific session by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        $rendererPref = $input->getOption('renderer') ?: $config->get('kosmokrator.ui.renderer', 'auto');
        $animated = ! $input->getOption('no-animation') && $config->get('kosmokrator.ui.intro_animated', true);

        // Always show the intro first
        $ui = new UIManager($rendererPref);
        $ui->initialize();
        $ui->renderIntro($animated);
        $ui->showWelcome();

        // Check if API key is configured — prompt setup if not
        $provider = $config->get('kosmokrator.agent.default_provider', 'z');
        $apiKey = $config->get("prism.providers.{$provider}.api_key", '');

        if ($apiKey === '' || $apiKey === null) {
            $r = "\033[0m";
            $dim = "\033[38;5;245m";
            $accent = "\033[38;2;255;200;80m";
            $white = "\033[1;37m";

            echo "{$accent}  ⚡ No API key configured.{$r}\n";
            echo "{$dim}  Run {$white}kosmokrator setup{$dim} to configure your provider and API key.{$r}\n\n";

            return Command::FAILURE;
        }

        $log = $this->container->make(LoggerInterface::class);
        $log->info('KosmoKrator started', ['renderer' => $ui->getActiveRenderer(), 'provider' => $provider]);

        $llm = ($ui->getActiveRenderer() === 'tui')
            ? $this->container->make(AsyncLlmClient::class)
            : $this->container->make(PrismService::class);
        $toolRegistry = $this->container->make(ToolRegistry::class);
        $permissions = $this->container->make(PermissionEvaluator::class);
        $models = $this->container->make(ModelCatalog::class);
        $sessionManager = $this->container->make(SessionManager::class);

        // Set project scope for settings/memories
        $project = InstructionLoader::gitRoot() ?? getcwd();
        $sessionManager->setProject($project);

        // Load persisted settings
        $this->applyPersistedSettings($sessionManager, $llm, $permissions);

        // Build system prompt: base + memories + instructions + environment
        $memoriesEnabled = ($sessionManager->getSetting('memories') ?? 'on') !== 'off';
        $memories = $memoriesEnabled ? $sessionManager->getMemories() : [];

        $baseSystemPrompt = $config->get('kosmokrator.agent.system_prompt', 'You are a helpful coding assistant.')
            . MemoryInjector::format($memories)
            . InstructionLoader::gather()
            . EnvironmentContext::gather();
        $maxRounds = (int) $config->get('kosmokrator.agent.max_tool_rounds', 25);
        $taskStore = $this->container->make(TaskStore::class);
        $ui->setTaskStore($taskStore);
        $autoCompactEnabled = ($sessionManager->getSetting('auto_compact') ?? 'on') !== 'off';
        $compactor = $autoCompactEnabled ? new ContextCompactor($llm, $models, $log) : null;
        $agentLoop = new AgentLoop($llm, $ui, $log, $baseSystemPrompt, $maxRounds, $permissions, $models, $taskStore, $sessionManager, $compactor);
        $agentLoop->setTools($toolRegistry->toPrismTools());

        // Session: resume or create new
        $resumeId = $input->getOption('session');
        if ($resumeId === null && $input->getOption('resume')) {
            $resumeId = $sessionManager->latestSession();
        }

        if ($resumeId !== null) {
            $sessionManager->setCurrentSession($resumeId);
            $history = $sessionManager->loadHistory($resumeId);
            if ($history->count() > 0) {
                $agentLoop->setHistory($history);
                $ui->showNotice("Resumed session ({$resumeId})");
            }
        } else {
            $modelName = $llm->getProvider() . '/' . $llm->getModel();
            $sessionManager->createSession($modelName);
        }

        return $this->repl($ui, $agentLoop, $permissions, $llm, $sessionManager);
    }

    private function repl(UIManager $ui, AgentLoop $agentLoop, PermissionEvaluator $permissions, LlmClientInterface $llm, SessionManager $sessionManager): int
    {
        $nextInput = null;

        while (true) {
            $input = $nextInput ?? $ui->prompt();
            $nextInput = null;

            if ($input === '') {
                continue;
            }

            $command = strtolower($input);

            if (in_array($command, ['/quit', '/exit', '/q'])) {
                $ui->teardown();
                break;
            }

            if ($command === '/seed') {
                $ui->seedMockSession();
                continue;
            }

            if ($command === '/clear') {
                echo "\033[2J\033[H";
                continue;
            }

            if ($command === '/compact') {
                $agentLoop->performCompaction();
                continue;
            }

            if ($command === '/reset') {
                $agentLoop->history()->clear();
                $permissions->resetGrants();
                $permissions->setAutoApprove(false);
                $modelName = $llm->getProvider() . '/' . $llm->getModel();
                $sessionManager->createSession($modelName);
                $ui->showNotice('Conversation cleared. New session started.');
                continue;
            }

            if ($command === '/prometheus') {
                $permissions->setAutoApprove(true);
                $ui->showNotice('⚡ Prometheus unbound — all tools auto-approved until next prompt.');
                continue;
            }

            if (in_array($command, ['/theogony', '/cosmogony'])) {
                $ui->playTheogony();
                continue;
            }

            if ($command === '/settings') {
                $memoriesEnabled = $sessionManager->getSetting('memories') ?? 'on';
                $autoCompact = $sessionManager->getSetting('auto_compact') ?? 'on';

                $currentSettings = [
                    'mode' => $agentLoop->getMode()->value,
                    'auto_approve' => $permissions->isAutoApprove() ? 'on' : 'off',
                    'memories' => $memoriesEnabled,
                    'auto_compact' => $autoCompact,
                    'temperature' => (string) ($llm->getTemperature() ?? 0.0),
                    'max_tokens' => (string) ($llm->getMaxTokens() ?? 8192),
                    'provider' => $llm->getProvider(),
                    'model' => $llm->getModel(),
                ];

                $changes = $ui->showSettings($currentSettings);

                foreach ($changes as $id => $value) {
                    match ($id) {
                        'mode' => (function () use ($agentLoop, $ui, $value, $sessionManager) {
                            $mode = AgentMode::from($value);
                            $agentLoop->setMode($mode);
                            $ui->showMode($mode->label(), $mode->color());
                            $sessionManager->setSetting('mode', $value);
                        })(),
                        'auto_approve' => (function () use ($permissions, $value, $sessionManager) {
                            $permissions->setAutoApprove($value === 'on');
                            $sessionManager->setSetting('auto_approve', $value);
                        })(),
                        'memories' => $sessionManager->setSetting('memories', $value),
                        'auto_compact' => $sessionManager->setSetting('auto_compact', $value),
                        'temperature' => (function () use ($llm, $value, $sessionManager) {
                            $llm->setTemperature((float) $value);
                            $sessionManager->setSetting('temperature', $value);
                        })(),
                        'max_tokens' => (function () use ($llm, $value, $sessionManager) {
                            $llm->setMaxTokens((int) $value);
                            $sessionManager->setSetting('max_tokens', $value);
                        })(),
                        default => null,
                    };
                }

                if ($changes !== []) {
                    $ui->showNotice('Settings updated: ' . implode(', ', array_keys($changes)));
                }
                continue;
            }

            if ($command === '/resume' || str_starts_with($command, '/resume ')) {
                $arg = trim(substr($input, 7));
                $sessionId = null;

                if ($arg === '') {
                    // Interactive picker
                    $sessions = $sessionManager->listSessions(10);
                    if ($sessions === []) {
                        $ui->showNotice('No sessions to resume.');
                        continue;
                    }

                    $items = [];
                    foreach ($sessions as $s) {
                        $current = $s['id'] === $sessionManager->currentSessionId() ? ' (current)' : '';
                        $msgCount = $s['message_count'] ?? 0;
                        $age = $this->formatAge($s['updated_at'] ?? '');
                        $preview = $s['last_user_message'] ?? $s['title'] ?? '(empty)';
                        $preview = mb_substr(trim(str_replace("\n", ' ', $preview)), 0, 50);

                        $items[] = [
                            'value' => $s['id'],
                            'label' => $preview . $current,
                            'description' => "{$msgCount} msgs, {$age}",
                        ];
                    }

                    $sessionId = $ui->pickSession($items);
                } else {
                    $found = $sessionManager->findSession($arg);
                    $sessionId = $found ? $found['id'] : null;
                    if ($sessionId === null) {
                        $ui->showNotice("No session found matching '{$arg}'.");
                        continue;
                    }
                }

                if ($sessionId !== null) {
                    $history = $sessionManager->resumeSession($sessionId);
                    $agentLoop->setHistory($history);
                    $permissions->resetGrants();
                    $session = $sessionManager->findSession($sessionId);
                    $title = $session['title'] ?? '(untitled)';
                    $ui->showNotice("Resumed: {$title} ({$history->count()} messages)");
                }
                continue;
            }

            if ($command === '/sessions') {
                $sessions = $sessionManager->listSessions(10);
                if ($sessions === []) {
                    $ui->showNotice('No sessions found for this project.');
                } else {
                    $lines = [];
                    foreach ($sessions as $s) {
                        $lines[] = $this->formatSessionLine($s, $sessionManager->currentSessionId());
                    }
                    $ui->showNotice("Recent sessions:\n" . implode("\n", $lines));
                }
                continue;
            }

            if ($command === '/memories') {
                $memories = $sessionManager->getMemories();
                if ($memories === []) {
                    $ui->showNotice('No memories stored yet.');
                } else {
                    $lines = [];
                    foreach ($memories as $m) {
                        $lines[] = "  [{$m['id']}] ({$m['type']}) {$m['title']}";
                    }
                    $ui->showNotice("Memories:\n" . implode("\n", $lines));
                }
                continue;
            }

            if (str_starts_with($command, '/forget ')) {
                $id = (int) trim(substr($input, 8));
                if ($id > 0) {
                    $sessionManager->deleteMemory($id);
                    $ui->showNotice("Memory #{$id} deleted.");
                } else {
                    $ui->showNotice('Usage: /forget <id>');
                }
                continue;
            }

            if (in_array($command, ['/edit', '/plan', '/ask'])) {
                $mode = AgentMode::from(ltrim($command, '/'));
                $agentLoop->setMode($mode);
                $ui->showMode($mode->label(), $mode->color());
                $sessionManager->setSetting('mode', $mode->value);
                $ui->showNotice("Switched to {$mode->label()} mode.");
                continue;
            }

            // Send to agent
            $ui->showUserMessage($input);
            $agentLoop->run($input);
            $permissions->setAutoApprove(false);

            // Check for messages queued during thinking
            $nextInput = $ui->consumeQueuedMessage();
        }

        $ui->teardown();

        return Command::SUCCESS;
    }

    private function applyPersistedSettings(SessionManager $sm, LlmClientInterface $llm, PermissionEvaluator $permissions): void
    {
        $temp = $sm->getSetting('temperature');
        if ($temp !== null) {
            $llm->setTemperature((float) $temp);
        }

        $maxTokens = $sm->getSetting('max_tokens');
        if ($maxTokens !== null) {
            $llm->setMaxTokens((int) $maxTokens);
        }

        $autoApprove = $sm->getSetting('auto_approve');
        if ($autoApprove === 'on') {
            $permissions->setAutoApprove(true);
        }
    }

    private function formatSessionLine(array $session, ?string $currentId): string
    {
        $id = substr($session['id'], 0, 8);
        $current = $session['id'] === $currentId ? ' ←' : '';
        $msgCount = $session['message_count'] ?? 0;
        $age = $this->formatAge($session['updated_at'] ?? '');

        // Use last user message as preview, fall back to title
        $preview = $session['last_user_message'] ?? $session['title'] ?? null;
        if ($preview !== null) {
            $preview = mb_substr(trim(str_replace("\n", ' ', $preview)), 0, 60);
        } else {
            $preview = '(empty)';
        }

        return "  {$id}  {$preview}  ({$msgCount} msgs, {$age}){$current}";
    }

    private function formatAge(string $timestamp): string
    {
        if ($timestamp === '') {
            return '?';
        }

        $seconds = time() - (int) ((float) $timestamp);

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return (int) ($seconds / 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return (int) ($seconds / 3600) . 'h ago';
        }

        return (int) ($seconds / 86400) . 'd ago';
    }
}
