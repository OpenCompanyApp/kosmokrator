<?php

declare(strict_types=1);

namespace KosmoKrator\UI\Tui\Input;

/**
 * Persistent input history store for the TUI prompt.
 *
 * Features:
 *  - Append entries with deduplication (consecutive duplicates collapsed)
 *  - FIFO eviction when exceeding max size
 *  - Up/Down navigation (circular, scoped to session or global)
 *  - Ctrl+R reverse incremental search
 *  - JSON file persistence at ~/.kosmokrator/input_history.json
 *  - Lazy loading on first access
 *
 * Thread safety: this class is designed for single-process TUI usage. The
 * persistence file is written atomically via temp-file + rename.
 */
final class InputHistory
{
    /** Default maximum number of history entries before FIFO eviction. */
    public const DEFAULT_MAX_SIZE = 1000;

    /** Path to the history file relative to the KosmoKrator data directory. */
    private const HISTORY_FILE = 'input_history.json';

    /** @var list<HistoryEntry>|null Lazy-loaded entries; null before first load. */
    private ?array $entries = null;

    /** Current navigation position (0 = most recent). null = not navigating. */
    private ?int $navIndex = null;

    /** The original editor text before navigation started. */
    private ?string $preNavText = null;

    // -- Reverse-search state --

    private bool $reverseSearchActive = false;

    private string $reverseSearchQuery = '';

    /** @var list<int> Entry indices matching the current reverse-search query, newest-first. */
    private array $reverseSearchMatches = [];

    /** Position within reverseSearchMatches during cycling. */
    private int $reverseSearchPosition = 0;

    /** The text that was in the editor when reverse search was initiated. */
    private ?string $preSearchText = null;

    /**
     * @param  string|null  $historyDir  Directory for the history file. Defaults to ~/.kosmokrator/data.
     * @param  int  $maxSize  Maximum number of entries before FIFO eviction.
     * @param  string|null  $sessionId  Current session ID to tag new entries.
     */
    public function __construct(
        private readonly ?string $historyDir = null,
        private readonly int $maxSize = self::DEFAULT_MAX_SIZE,
        private ?string $sessionId = null,
    ) {}

    /**
     * Set or update the current session ID for tagging new entries.
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    // ---------------------------------------------------------------
    // Entry management
    // ---------------------------------------------------------------

    /**
     * Append a new entry to the history.
     *
     * Deduplicates against the most recent entry (identical consecutive text is
     * collapsed). Triggers FIFO eviction if the max size is exceeded. Persists
     * immediately to disk.
     *
     * Empty or whitespace-only text is silently ignored.
     */
    public function add(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $this->load();

        // Deduplicate: collapse identical consecutive entries
        if ($this->entries !== []) {
            $last = $this->entries[array_key_last($this->entries)];
            if ($last->text === $text) {
                return;
            }
        }

        $this->entries[] = new HistoryEntry(
            text: $text,
            timestamp: microtime(true),
            sessionId: $this->sessionId,
        );

        $this->evict();
        $this->persist();

        // Reset navigation state after adding
        $this->resetNavigation();
    }

    /**
     * Return all entries, newest last.
     *
     * @return list<HistoryEntry>
     */
    public function all(): array
    {
        $this->load();

        return $this->entries;
    }

    /**
     * Return entries optionally filtered by session ID.
     *
     * @return list<HistoryEntry>
     */
    public function forSession(?string $sessionId = null): array
    {
        $this->load();

        if ($sessionId === null) {
            return $this->entries;
        }

        return array_values(
            array_filter($this->entries, fn (HistoryEntry $e): bool => $e->sessionId === $sessionId)
        );
    }

    /**
     * Total number of entries.
     */
    public function count(): int
    {
        $this->load();

        return count($this->entries);
    }

    // ---------------------------------------------------------------
    // Up / Down navigation
    // ---------------------------------------------------------------

    /**
     * Move to the previous (older) entry and return its text.
     *
     * Returns null when there are no older entries to navigate to.
     * The first call starts navigation from the most recent entry.
     *
     * @param  string  $currentText  The current editor text, saved before first navigation.
     * @return string|null The recalled text, or null if no older entry exists.
     */
    public function navigateOlder(string $currentText): ?string
    {
        $this->load();

        if ($this->entries === []) {
            return null;
        }

        // Start navigation: save current text and jump to the most recent entry
        if ($this->navIndex === null) {
            $this->preNavText = $currentText;
            $this->navIndex = 0;

            return $this->entries[array_key_last($this->entries) - $this->navIndex]->text;
        }

        $maxIndex = count($this->entries) - 1;
        if ($this->navIndex >= $maxIndex) {
            return null; // Already at the oldest entry
        }

        $this->navIndex++;

        return $this->entries[array_key_last($this->entries) - $this->navIndex]->text;
    }

    /**
     * Move to the next (newer) entry and return its text.
     *
     * Returns the saved pre-navigation text when reaching the end.
     * Returns null if not currently navigating.
     */
    public function navigateNewer(): ?string
    {
        if ($this->navIndex === null) {
            return null;
        }

        if ($this->navIndex <= 0) {
            // Return to the pre-navigation state
            $text = $this->preNavText;
            $this->resetNavigation();

            return $text;
        }

        $this->navIndex--;

        return $this->entries[array_key_last($this->entries) - $this->navIndex]->text;
    }

    /**
     * Whether the history is currently in navigation mode.
     */
    public function isNavigating(): bool
    {
        return $this->navIndex !== null;
    }

    /**
     * Reset navigation state, typically after submitting or editing.
     */
    public function resetNavigation(): void
    {
        $this->navIndex = null;
        $this->preNavText = null;
    }

    // ---------------------------------------------------------------
    // Reverse search (Ctrl+R)
    // ---------------------------------------------------------------

    /**
     * Enter reverse-search mode.
     *
     * @param  string  $currentText  The current editor text, saved for restoration on cancel.
     */
    public function startReverseSearch(string $currentText): void
    {
        $this->load();
        $this->reverseSearchActive = true;
        $this->reverseSearchQuery = '';
        $this->reverseSearchMatches = [];
        $this->reverseSearchPosition = 0;
        $this->preSearchText = $currentText;
    }

    /**
     * Whether reverse-search mode is currently active.
     */
    public function isReverseSearching(): bool
    {
        return $this->reverseSearchActive;
    }

    /**
     * Update the reverse-search query and return the best matching entry text.
     *
     * Returns null if no match is found for the updated query.
     */
    public function updateReverseSearch(string $query): ?string
    {
        $this->reverseSearchQuery = $query;
        $this->reverseSearchMatches = $this->findMatches($query);
        $this->reverseSearchPosition = 0;

        if ($this->reverseSearchMatches === []) {
            return null;
        }

        return $this->entries[$this->reverseSearchMatches[0]]->text;
    }

    /**
     * Cycle to the next (older) match in the current reverse search.
     *
     * Returns null if there are no more matches.
     */
    public function cycleReverseSearch(): ?string
    {
        if (! $this->reverseSearchActive || $this->reverseSearchMatches === []) {
            return null;
        }

        $this->reverseSearchPosition = ($this->reverseSearchPosition + 1) % count($this->reverseSearchMatches);

        return $this->entries[$this->reverseSearchMatches[$this->reverseSearchPosition]]->text;
    }

    /**
     * Accept the current reverse-search match and exit search mode.
     *
     * Returns the matched text (or null if no match), and resets the search state.
     */
    public function acceptReverseSearch(): ?string
    {
        if (! $this->reverseSearchActive) {
            return null;
        }

        $text = null;
        if ($this->reverseSearchMatches !== []) {
            $idx = $this->reverseSearchMatches[$this->reverseSearchPosition] ?? $this->reverseSearchMatches[0];
            $text = $this->entries[$idx]->text;
        }

        $this->endReverseSearch();

        return $text;
    }

    /**
     * Cancel reverse search and restore the pre-search text.
     *
     * Returns the text that was in the editor before the search started.
     */
    public function cancelReverseSearch(): ?string
    {
        $text = $this->preSearchText;
        $this->endReverseSearch();

        return $text;
    }

    /**
     * Get the current reverse-search query string (for display in the UI).
     */
    public function getReverseSearchQuery(): string
    {
        return $this->reverseSearchQuery;
    }

    /**
     * Build a display string for the reverse-search prompt.
     *
     * Example: "reverse-search:`query`> matched text preview"
     */
    public function getReverseSearchDisplay(): string
    {
        if (! $this->reverseSearchActive) {
            return '';
        }

        $query = $this->reverseSearchQuery;
        $preview = '';
        if ($this->reverseSearchMatches !== []) {
            $idx = $this->reverseSearchMatches[$this->reverseSearchPosition] ?? $this->reverseSearchMatches[0];
            $preview = $this->entries[$idx]->text;
        }

        return "reverse-search:`{$query}`> {$preview}";
    }

    // ---------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------

    /**
     * Force a reload from disk on next access.
     */
    public function invalidate(): void
    {
        $this->entries = null;
    }

    /**
     * Clear all history entries and delete the persistence file.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->persist();
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Lazy-load entries from the JSON file.
     */
    private function load(): void
    {
        if ($this->entries !== null) {
            return;
        }

        $path = $this->filePath();
        if (! file_exists($path)) {
            $this->entries = [];

            return;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            $this->entries = [];

            return;
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            $this->entries = [];

            return;
        }

        $this->entries = [];
        foreach ($data as $row) {
            if (isset($row['text']) && is_string($row['text'])) {
                $this->entries[] = HistoryEntry::fromArray([
                    'text' => $row['text'],
                    'timestamp' => (float) ($row['timestamp'] ?? microtime(true)),
                    'session_id' => $row['session_id'] ?? null,
                ]);
            }
        }
    }

    /**
     * Persist entries to disk atomically (temp file + rename).
     */
    private function persist(): void
    {
        if ($this->entries === null) {
            return;
        }

        $dir = $this->historyDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $data = array_map(fn (HistoryEntry $e): array => $e->toArray(), $this->entries);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = $this->filePath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    /**
     * Evict the oldest entries that exceed maxSize.
     */
    private function evict(): void
    {
        if (count($this->entries) <= $this->maxSize) {
            return;
        }

        $overflow = count($this->entries) - $this->maxSize;
        $this->entries = array_values(array_slice($this->entries, $overflow));
    }

    /**
     * Find entries matching the given query string, returning indices newest-first.
     *
     * @return list<int>
     */
    private function findMatches(string $query): array
    {
        if ($query === '' || $this->entries === []) {
            return [];
        }

        $lower = mb_strtolower($query);
        $matches = [];

        // Walk from newest to oldest
        for ($i = array_key_last($this->entries); $i >= 0; $i--) {
            if (mb_strpos(mb_strtolower($this->entries[$i]->text), $lower) !== false) {
                $matches[] = $i;
            }
        }

        return $matches;
    }

    /**
     * Reset all reverse-search state.
     */
    private function endReverseSearch(): void
    {
        $this->reverseSearchActive = false;
        $this->reverseSearchQuery = '';
        $this->reverseSearchMatches = [];
        $this->reverseSearchPosition = 0;
        $this->preSearchText = null;
    }

    /**
     * Resolve the history directory path.
     */
    private function historyDir(): string
    {
        if ($this->historyDir !== null) {
            return $this->historyDir;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '/tmp';

        return $home . '/.kosmokrator/data';
    }

    /**
     * Resolve the full path to the history file.
     */
    private function filePath(): string
    {
        return $this->historyDir() . '/' . self::HISTORY_FILE;
    }
}
