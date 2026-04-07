<?php

declare(strict_types=1);

namespace Kosmokrator\UI\Tui\Performance;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Memory profiling and reporting for the TUI layer.
 *
 * Tracks memory snapshots at key lifecycle points (init, prompt, streaming,
 * tool result, compaction, teardown) and generates reports accessible via:
 *
 *   - **SIGUSR1** signal: `kill -USR1 <pid>` dumps a full report to a temp file.
 *   - **`/mem` command**: Returns a formatted report string for display in the TUI.
 *   - **Status bar**: Shows a compact `mem:XXm` indicator when profiling is enabled.
 *
 * Enabled via environment variable: `KOSMOKRATOR_MEM_PROFILE=1`
 *
 * Usage:
 *   $profiler = MemoryProfiler::createIfEnabled($conversation);
 *
 *   // At lifecycle points:
 *   $profiler?->takeSnapshot('init');
 *   $profiler?->takeSnapshot('response-5');
 *
 *   // On demand:
 *   $report = $profiler?->generateReport();
 *
 *   // Install signal handler:
 *   $profiler?->installSignalHandler();
 *
 * @see docs/plans/tui-overhaul/13-architecture/01-memory-profiling.md
 */
final class MemoryProfiler
{
    /** @var list<MemorySnapshot> Ordered snapshots taken at lifecycle points */
    private array $snapshots = [];

    /** @var array<string, int> Per-component memory estimates (label → bytes) */
    private array $componentEstimates = [];

    /** @var int Turn counter (incremented externally via snapshot labels or setTurn()) */
    private int $turn = 0;

    /** @var float Session start time for elapsed calculations */
    private readonly float $startTime;

    /** Whether SIGUSR1 handler has been installed */
    private bool $signalHandlerInstalled = false;

    // ── Static API ─────────────────────────────────────────────────────

    /** @var self|null Singleton for static method access */
    private static ?self $globalInstance = null;

    /** @var int Tracked peak memory usage across all static snapshots */
    private static int $staticPeak = 0;

    /** @var list<array{label: string, memory: int, peak: int, timestamp: float}> Static snapshot log */
    private static array $staticSnapshots = [];

    /**
     * Take a static memory snapshot with the given label.
     *
     * Works without an instance — tracks memory and peak values globally.
     * Can be called from anywhere without dependency injection.
     */
    public static function snapshot(string $label): void
    {
        $memory = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        if ($peak > self::$staticPeak) {
            self::$staticPeak = $peak;
        }

        self::$staticSnapshots[] = [
            'label' => $label,
            'memory' => $memory,
            'peak' => $peak,
            'timestamp' => microtime(true),
        ];

        // Also delegate to global instance if available
        self::$globalInstance?->takeSnapshot($label);
    }

    /**
     * Get all memory snapshots (merges static and instance snapshots).
     *
     * @return list<array{label: string, memory: int, peak: int, timestamp: float}>
     */
    public static function getSnapshots(): array
    {
        return self::$staticSnapshots;
    }

    /**
     * Get the tracked peak memory usage in bytes.
     */
    public static function getPeak(): int
    {
        $currentPeak = memory_get_peak_usage(true);
        if ($currentPeak > self::$staticPeak) {
            self::$staticPeak = $currentPeak;
        }

        return self::$staticPeak;
    }

    /**
     * Generate a memory report string.
     *
     * If a global instance is available, delegates to its full report.
     * Otherwise produces a compact static-only report.
     */
    public static function report(): string
    {
        if (self::$globalInstance !== null) {
            return self::$globalInstance->generateReport();
        }

        return self::generateStaticReport();
    }

    /**
     * Set the global profiler instance for static method delegation.
     */
    public static function setGlobalInstance(?self $instance): void
    {
        self::$globalInstance = $instance;
    }

    /**
     * Reset all static state.
     */
    public static function resetStatic(): void
    {
        self::$staticPeak = 0;
        self::$staticSnapshots = [];
        self::$globalInstance = null;
    }

    private static function generateStaticReport(): string
    {
        $current = memory_get_usage(true);
        $peak = self::getPeak();

        $lines = [];
        $lines[] = 'Memory Profile (static)';
        $lines[] = str_repeat('━', 40);
        $lines[] = sprintf('Current: %s (peak: %s)', self::formatBytesStatic($current), self::formatBytesStatic($peak));
        $lines[] = sprintf('AnsiStringPool: %d entries, %s', AnsiStringPool::size(), self::formatBytesStatic(AnsiStringPool::estimatedBytes()));

        if (self::$staticSnapshots !== []) {
            $lines[] = '';
            $lines[] = 'Snapshots:';
            $previous = null;
            foreach (self::$staticSnapshots as $snap) {
                $delta = $previous !== null
                    ? sprintf('  (+%s)', self::formatBytesStatic($snap['memory'] - $previous))
                    : '';
                $lines[] = sprintf('  %-20s %s%s', $snap['label'], self::formatBytesStatic($snap['memory']), $delta);
                $previous = $snap['memory'];
            }
        }

        return implode("\n", $lines);
    }

    private static function formatBytesStatic(int $bytes): string
    {
        if ($bytes < 0) {
            return '-' . self::formatBytesStatic(abs($bytes));
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    /**
     * @param ContainerWidget $conversation The main conversation container for widget counting
     * @param bool $enabled Whether profiling is active (set from env var)
     */
    private function __construct(
        private readonly ContainerWidget $conversation,
        private readonly bool $enabled = true,
    ) {
        $this->startTime = microtime(true);
    }

    /**
     * Factory: create a MemoryProfiler only if profiling is enabled.
     *
     * Reads the `KOSMOKRATOR_MEM_PROFILE` environment variable.
     * Returns null when profiling is disabled, so callers can use `?->` for zero overhead.
     */
    public static function createIfEnabled(ContainerWidget $conversation): ?self
    {
        $enabled = ($_SERVER['KOSMOKRATOR_MEM_PROFILE'] ?? $_ENV['KOSMOKRATOR_MEM_PROFILE'] ?? '') === '1';

        if (! $enabled) {
            return null;
        }

        $instance = new self($conversation, true);
        self::$globalInstance = $instance;

        return $instance;
    }

    /**
     * Create a profiler instance regardless of env var (for testing).
     */
    public static function create(ContainerWidget $conversation): self
    {
        $instance = new self($conversation, true);
        self::$globalInstance = $instance;

        return $instance;
    }

    // ── Snapshots ───────────────────────────────────────────────────────

    /**
     * Take a memory snapshot at a lifecycle point.
     *
     * Recommended labels: 'init', 'intro', 'pre-prompt-N', 'user-msg-N',
     * 'response-N', 'tool-N', 'compact-N', 'teardown'.
     *
     * No-op when profiling is disabled (but this instance should be null already).
     */
    public function takeSnapshot(string $label): void
    {
        $this->snapshots[] = new MemorySnapshot(
            label: $label,
            timestamp: microtime(true),
            memoryUsage: memory_get_usage(true),
            memoryUsageReal: memory_get_usage(false),
            memoryPeak: memory_get_peak_usage(true),
            widgetCount: count($this->conversation->all()),
            turn: $this->turn,
        );
    }

    /**
     * Set the current turn number.
     */
    public function setTurn(int $turn): void
    {
        $this->turn = $turn;
    }

    /**
     * Get the current turn number.
     */
    public function getTurn(): int
    {
        return $this->turn;
    }

    // ── Component Estimates ─────────────────────────────────────────────

    /**
     * Record a per-component memory estimate.
     *
     * Call from external code that has visibility into component internals:
     *   $profiler->recordComponent('subagent_display', $estimatedBytes);
     */
    public function recordComponent(string $name, int $estimatedBytes): void
    {
        $this->componentEstimates[$name] = $estimatedBytes;
    }

    /**
     * Measure the memory delta of a callable.
     *
     * Records the delta under the given label and returns the callable's result.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function measure(string $label, callable $fn): mixed
    {
        $before = memory_get_usage(false);
        $result = $fn();
        $after = memory_get_usage(false);
        $delta = $after - $before;

        if (abs($delta) > 1024) {
            $this->componentEstimates[$label] = ($this->componentEstimates[$label] ?? 0) + $delta;
        }

        return $result;
    }

    // ── Reporting ───────────────────────────────────────────────────────

    /**
     * Generate a formatted memory report.
     *
     * Output format:
     * ```
     * Memory Profile (turn 12, 4m32s elapsed)
     * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
     * Total: 28.3 MB (peak: 35.1 MB)
     *
     * Conversation widgets: 142 widgets
     * AnsiStringPool: 48 entries, 1.2 KB
     *
     * Snapshots:
     *   init            8.0 MB    0 widgets
     *   response-5     14.2 MB   42 widgets  (+6.2 MB)
     *   tool-10        18.5 MB   78 widgets  (+4.3 MB)
     *   response-15    25.1 MB  120 widgets  (+6.6 MB)
     *
     * Growth rate: +1.4 MB/turn
     * ```
     */
    public function generateReport(): string
    {
        $elapsed = microtime(true) - $this->startTime;
        $elapsedFormatted = $this->formatDuration($elapsed);

        $currentUsage = memory_get_usage(true);
        $currentPeak = memory_get_peak_usage(true);
        $widgetCount = count($this->conversation->all());

        $lines = [];
        $lines[] = "Memory Profile (turn {$this->turn}, {$elapsedFormatted} elapsed)";
        $lines[] = str_repeat('━', 50);
        $lines[] = sprintf(
            'Total: %s (peak: %s)',
            $this->formatBytes($currentUsage),
            $this->formatBytes($currentPeak),
        );
        $lines[] = '';
        $lines[] = "Conversation widgets: {$widgetCount}";

        // AnsiStringPool stats
        $poolStats = AnsiStringPool::stats();
        $poolSize = AnsiStringPool::size();
        $poolBytes = AnsiStringPool::estimatedBytes();
        $lines[] = sprintf(
            'AnsiStringPool: %d entries, %s (hit rate: %.1f%%)',
            $poolSize,
            $this->formatBytes($poolBytes),
            $poolStats['hit_rate'] * 100,
        );

        // Component estimates
        if ($this->componentEstimates !== []) {
            $lines[] = '';
            $lines[] = 'Component estimates:';
            arsort($this->componentEstimates);
            foreach ($this->componentEstimates as $name => $bytes) {
                $lines[] = sprintf('  %-30s %s', $name, $this->formatBytes(abs($bytes)));
            }
        }

        // Snapshots
        if ($this->snapshots !== []) {
            $lines[] = '';
            $lines[] = 'Snapshots:';
            $previousUsage = null;
            foreach ($this->snapshots as $snapshot) {
                $delta = $previousUsage !== null
                    ? sprintf('  (+%s)', $this->formatBytes($snapshot->memoryUsage - $previousUsage))
                    : '';
                $lines[] = sprintf(
                    '  %-20s %s  %3d widgets%s',
                    $snapshot->label,
                    $this->formatBytes($snapshot->memoryUsage),
                    $snapshot->widgetCount,
                    $delta,
                );
                $previousUsage = $snapshot->memoryUsage;
            }
        }

        // Growth rate
        if (count($this->snapshots) >= 2 && $this->turn > 0) {
            $first = $this->snapshots[0];
            $last = $this->snapshots[count($this->snapshots) - 1];
            $growth = $last->memoryUsage - $first->memoryUsage;
            $turnsElapsed = $last->turn - $first->turn;

            if ($turnsElapsed > 0) {
                $perTurn = $growth / $turnsElapsed;
                $lines[] = '';
                $lines[] = sprintf('Growth rate: %s/turn', $this->formatBytes((int) abs($perTurn)));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate a compact one-line memory status for the status bar.
     *
     * Example: `mem:28.3m`
     */
    public function statusLine(): string
    {
        $mb = memory_get_usage(true) / (1024 * 1024);

        return sprintf('mem:%.1fm', $mb);
    }

    /**
     * Get all recorded instance snapshots.
     *
     * @return list<MemorySnapshot>
     */
    public function getInstanceSnapshots(): array
    {
        return $this->snapshots;
    }

    // ── Signal Handler ──────────────────────────────────────────────────

    /**
     * Install a SIGUSR1 handler that dumps a memory report to a temp file.
     *
     * Safe to call multiple times — only installs once.
     * Requires the `pcntl` extension.
     */
    public function installSignalHandler(): void
    {
        if ($this->signalHandlerInstalled) {
            return;
        }

        if (! \function_exists('pcntl_signal')) {
            return;
        }

        $this->signalHandlerInstalled = true;

        pcntl_async_signals(true);
        pcntl_signal(\SIGUSR1, function (): void {
            $report = $this->generateReport();
            $path = '/tmp/kosmokrator-mem-' . getmypid() . '.txt';
            file_put_contents($path, $report . "\n");
        });
    }

    // ── Formatting Helpers ──────────────────────────────────────────────

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return '-' . $this->formatBytes(abs($bytes));
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    private function formatDuration(float $seconds): string
    {
        $mins = (int) floor($seconds / 60);
        $secs = (int) floor($seconds % 60);

        if ($mins > 0) {
            return sprintf('%dm%02ds', $mins, $secs);
        }

        return sprintf('%ds', $secs);
    }
}

/**
 * Immutable memory snapshot taken at a lifecycle point.
 */
final class MemorySnapshot
{
    public function __construct(
        public readonly string $label,
        public readonly float $timestamp,
        public readonly int $memoryUsage,
        public readonly int $memoryUsageReal,
        public readonly int $memoryPeak,
        public readonly int $widgetCount,
        public readonly int $turn,
    ) {}
}
