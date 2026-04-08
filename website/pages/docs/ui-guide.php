<?php
$docTitle = 'UI Guide';
$docSlug = 'ui-guide';
ob_start();
?>

<p class="lead">
    KosmoKrator ships with two rendering modes — a full-screen interactive TUI and a lightweight
    ANSI fallback — so it works everywhere from modern GPU-accelerated terminals to bare SSH sessions.
    This guide covers how each mode works, what to expect, and how to get the best experience.
</p>

<!-- ================================================================== -->
<h2 id="dual-renderer-system">Dual Renderer System</h2>
<!-- ================================================================== -->

<p>
    At startup KosmoKrator checks whether the <strong>Symfony TUI</strong> library is available
    (<code>class_exists(Tui::class)</code>). If the library is installed, it launches in
    <strong>TUI mode</strong>. If the library is not present — for example in a minimal installation
    or inside a basic SSH session — it falls back to <strong>ANSI mode</strong>, which uses only
    standard ANSI escape codes and <code>readline</code> for input.
</p>

<p>
    You can override the auto-detection with a CLI flag:
</p>

<table>
    <thead>
        <tr>
            <th>Flag</th>
            <th>Effect</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>--renderer=tui</code></td>
            <td>Force TUI mode. Silently falls back to ANSI if the Symfony TUI library is not available.</td>
        </tr>
        <tr>
            <td><code>--renderer=ansi</code></td>
            <td>Force ANSI mode regardless of terminal capabilities.</td>
        </tr>
        <tr>
            <td><em>(omit)</em></td>
            <td>Auto-detect. TUI when possible, ANSI otherwise.</td>
        </tr>
        <tr>
            <td><code>--no-animation</code></td>
            <td>Disable breathing animation and other visual effects in TUI mode. Useful over slow connections.</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> If the TUI renders but feels sluggish over a remote connection,
        run with <code>--renderer=ansi</code> for a snappier experience. ANSI mode sends far fewer
        escape sequences per frame.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="tui-mode">TUI Mode</h2>
<!-- ================================================================== -->

<p>
    The TUI is built on <strong>Symfony TUI</strong> with a <strong>Revolt</strong> event loop,
    giving it a responsive, non-blocking architecture that updates the screen in real time while
    the agent is working.
</p>

<h3 id="tui-editor">Editor Widget</h3>

<p>
    The <code>EditorWidget</code> provides a full multi-line input area. Press
    <kbd>Shift+Enter</kbd> to insert a new line without submitting, and <kbd>Enter</kbd> to send
    the message. The editor supports basic cursor movement, selection, and clipboard paste.
</p>

<h3 id="tui-overlays">Overlay Dialogs</h3>

<p>
    Interactive prompts — permission requests, settings panels, the session picker — appear as
    overlay dialogs added on top of the conversation. They can be dismissed with
    <kbd>Escape</kbd> and navigated with arrow keys.
</p>

<h3 id="tui-animations">Animations &amp; Activity Indicators</h3>

<p>
    While the agent is processing, the TUI shows a live <strong>breathing animation</strong> and a
    spinning indicator. Subagent activity is reflected in real time via the
    <code>SubagentDisplayManager</code>, which refreshes the agent tree overlay as children spawn
    and complete.
</p>

<p>
    <strong>Toast notifications</strong> appear briefly for transient events — tool completions,
    errors, or status changes — and fade away automatically. They provide feedback without
    interrupting the conversation flow.
</p>

<h3 id="tui-swarm-dashboard">Swarm Dashboard</h3>

<p>
    Press <kbd>Ctrl+A</kbd> at any time to open the <strong>swarm dashboard</strong>, an overlay
    widget that shows every active subagent, its status, elapsed time, and dependency edges. Press
    <kbd>Ctrl+A</kbd> again (or <kbd>Escape</kbd>) to dismiss it.
</p>

<h3 id="tui-syntax-highlighting">Syntax Highlighting &amp; Markdown</h3>

<p>
    Code blocks in agent responses are syntax-highlighted via
    <strong>tempest/highlight</strong> with a custom <code>KosmokratorTerminalTheme</code> that
    matches the KosmoKrator color palette. Markdown rendering is handled by Symfony TUI's
    <code>MarkdownWidget</code> with a custom stylesheet tuned for terminal readability.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Use <kbd>Ctrl+O</kbd> to collapse or expand tool output blocks inline.
        When collapsed, you see just the tool icon and a one-line summary — useful for scanning
        long conversations.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="ansi-mode">ANSI Mode</h2>
<!-- ================================================================== -->

<p>
    ANSI mode uses pure ANSI escape codes for color and formatting, with PHP's
    <code>readline</code> extension for line editing and history. It works in literally any
    terminal — no alternate screen buffer, no event loop, no special capabilities required.
</p>

<h3 id="ansi-markdown">Markdown Formatting</h3>

<p>
    Agent responses are formatted by <code>MarkdownToAnsi</code>, which converts CommonMark AST
    nodes into ANSI-colored text. Specialized handlers extract tables and nested lists for clean
    alignment. The markdown parsing itself uses <strong>league/commonmark</strong>.
</p>

<h3 id="ansi-tables">Table Rendering</h3>

<p>
    The <code>AnsiTableRenderer</code> produces aligned, bordered tables using box-drawing
    characters, automatically truncated to the terminal width.
</p>

<h3 id="ansi-agent-tree">Agent Tree Display</h3>

<p>
    When subagents are active, the ANSI renderer shows a tree of running agents using
    box-drawing characters (<code>├─</code>, <code>└─</code>) via the shared
    <code>AgentDisplayFormatter</code>.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> ANSI mode does not have a multi-line editor. If you need to compose
        long prompts, use the <code>/seed</code> command to load content from your preferred editor
        or paste from the clipboard.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="shared-components">Shared Components</h2>
<!-- ================================================================== -->

<p>
    Both renderers share a common set of UI components that ensure a consistent look regardless of
    which mode is active:
</p>

<table>
    <thead>
        <tr>
            <th>Component</th>
            <th>Purpose</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>Theme.php</code></td>
            <td>Shared color palette and tool icons. Icons are celestial/planetary symbols:
                <code>☽</code> (Moon) for file reads, <code>☉</code> (Sun) for file writes,
                <code>♅</code> (Uranus) for edits/patches, <code>✧</code> for glob,
                <code>⊛</code> for grep, <code>⚡︎</code> for bash, <code>♃</code> (Jupiter) for
                subagents, <code>♄</code> (Saturn) for memory, <code>☿</code> (Mercury) for Lua,
                and more.</td>
        </tr>
        <tr>
            <td><code>AgentDisplayFormatter</code></td>
            <td>Static utilities: <code>formatAgentLabel</code>, <code>formatElapsed</code>,
                <code>formatAgentStats</code>, <code>renderChildTree</code>. Used by both renderers
                to display agent hierarchy.</td>
        </tr>
        <tr>
            <td><code>AgentTreeBuilder</code></td>
            <td>Builds a structured agent tree from orchestrator stats, consumed by the TUI swarm
                dashboard and the ANSI tree display alike.</td>
        </tr>
        <tr>
            <td><code>KosmokratorTerminalTheme</code></td>
            <td>Syntax highlighting color definitions shared between TUI (tempest/highlight) and
                ANSI (inline coloring).</td>
        </tr>
        <tr>
            <td><code>UI/Diff/</code></td>
            <td>Unified diff rendering with word-level highlighting. Produces colored
                <code>+/-</code> lines in both TUI and ANSI modes.</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="terminal-compatibility">Terminal Compatibility</h2>
<!-- ================================================================== -->

<table>
    <thead>
        <tr>
            <th>Terminal</th>
            <th>TUI Support</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>iTerm2</strong></td>
            <td>Full</td>
            <td>Recommended on macOS. Full true-color and Unicode support.</td>
        </tr>
        <tr>
            <td><strong>Alacritty</strong></td>
            <td>Full</td>
            <td>GPU-accelerated. Excellent performance.</td>
        </tr>
        <tr>
            <td><strong>Kitty</strong></td>
            <td>Full</td>
            <td>GPU-accelerated with full Unicode support.</td>
        </tr>
        <tr>
            <td><strong>WezTerm</strong></td>
            <td>Full</td>
            <td>Multiplx-aware, good Unicode and true-color.</td>
        </tr>
        <tr>
            <td><strong>GNOME Terminal</strong></td>
            <td>Good</td>
            <td>Full features. May need to enable 256-color in preferences.</td>
        </tr>
        <tr>
            <td><strong>Konsole</strong></td>
            <td>Good</td>
            <td>Full features on KDE. Ensure Unicode is enabled.</td>
        </tr>
        <tr>
            <td><strong>macOS Terminal.app</strong></td>
            <td>ANSI recommended</td>
            <td>Limited Unicode and color support. ANSI mode auto-selected.</td>
        </tr>
        <tr>
            <td><strong>Windows Terminal</strong> (via WSL)</td>
            <td>Full</td>
            <td>Best Windows experience. Use with WSL2.</td>
        </tr>
        <tr>
            <td><strong>SSH sessions</strong></td>
            <td>ANSI (auto-detected)</td>
            <td>Fallback depends on remote <code>$TERM</code>. Use <code>-o "RequestTTY yes"</code> for better results.</td>
        </tr>
        <tr>
            <td><strong>CI / headless</strong></td>
            <td>Streaming (no renderer)</td>
            <td>Agent output streams as plain text. Use <code>--renderer=ansi</code> for non-interactive CI.</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> The status line at the top of the TUI shows the current
        <strong>mode label</strong> (e.g. "Code", "Plan"), the <strong>permission label</strong>,
        and <strong>token/model details</strong> — so you always know the active configuration at a glance.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="context-bar">Context Bar</h2>
<!-- ================================================================== -->

<p>
    In TUI mode, a <strong>context bar</strong> at the bottom of the screen displays real-time
    context window usage. It shows the current token count as a percentage of the configured
    maximum:
</p>

<table>
    <thead>
        <tr>
            <th>Color</th>
            <th>Threshold</th>
            <th>Meaning</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Green</strong></td>
            <td>0–50%</td>
            <td>Plenty of headroom.</td>
        </tr>
        <tr>
            <td><strong>Yellow</strong></td>
            <td>50–75%</td>
            <td>Warning. Compaction may be triggered soon.</td>
        </tr>
        <tr>
            <td><strong>Red</strong></td>
            <td>75%+</td>
            <td>Critical. Auto-compaction or pruning will activate.</td>
        </tr>
    </tbody>
</table>

<p>
    The context bar also shows active tasks as an indented tree — the parent task and any running
    subagents — so you always know what the agent is working on without opening the full swarm
    dashboard.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> When the bar turns red, the agent will automatically compact the
        conversation history. You can trigger this manually with <code>/compact</code> at any time.
        See <a href="/docs/context">Context &amp; Memory</a> for details.
    </p>
</div>

<!-- ================================================================== -->
<h2 id="output-display">Output Display</h2>
<!-- ================================================================== -->

<p>
    Tool results and agent responses are rendered differently depending on the active mode:
</p>

<h3 id="output-tui">TUI Output</h3>

<ul>
    <li><strong>Inline widgets</strong> — Tool results appear as collapsible blocks inside the
        conversation. Press <kbd>Ctrl+O</kbd> to toggle collapse/expand.</li>
    <li><strong>Diff highlighting</strong> — File edits are rendered as unified diffs with
        word-level color highlighting via <code>UI/Diff/</code>.</li>
    <li><strong>Code with syntax highlighting</strong> — Code blocks use
        <code>KosmokratorTerminalTheme</code> for consistent colors across all supported languages.</li>
    <li><strong>Markdown</strong> — Agent prose is rendered with headers, bold, italic, links,
        and bullet lists via the custom <code>MarkdownWidget</code> stylesheet.</li>
</ul>

<h3 id="output-ansi">ANSI Output</h3>

<ul>
    <li><strong>Colored blocks with tool icons</strong> — Each tool call is prefixed with its
        celestial symbol icon from <code>Theme.php</code> and colored to indicate success/failure.</li>
    <li><strong>Truncated to terminal width</strong> — All output respects <code>$COLUMNS</code>.
        Long lines are wrapped or truncated to prevent horizontal scroll.</li>
    <li><strong>Line-by-line streaming</strong> — Agent responses stream as they are generated,
        with ANSI-colored text appearing line by line.</li>
</ul>

<h3 id="output-comparison">Feature Comparison</h3>

<table>
    <thead>
        <tr>
            <th>Feature</th>
            <th>TUI Mode</th>
            <th>ANSI Mode</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Multi-line input editor</td>
            <td>Yes (Shift+Enter)</td>
            <td>No — use <code>/seed</code></td>
        </tr>
        <tr>
            <td>Overlay dialogs</td>
            <td>Yes</td>
            <td>No — inline prompts</td>
        </tr>
        <tr>
            <td>Swarm dashboard</td>
            <td>Overlay (Ctrl+A)</td>
            <td>Inline tree display</td>
        </tr>
        <tr>
            <td>Syntax highlighting</td>
            <td>Full (tempest/highlight)</td>
            <td>Basic ANSI colors</td>
        </tr>
        <tr>
            <td>Markdown rendering</td>
            <td>Full (MarkdownWidget)</td>
            <td>ANSI-formatted (MarkdownToAnsi)</td>
        </tr>
        <tr>
            <td>Collapsible output</td>
            <td>Yes (Ctrl+O)</td>
            <td>No</td>
        </tr>
        <tr>
            <td>Diff highlighting</td>
            <td>Word-level</td>
            <td>Line-level</td>
        </tr>
        <tr>
            <td>Context bar</td>
            <td>Live percentage bar</td>
            <td>Periodic text summary</td>
        </tr>
        <tr>
            <td>Breathing animation</td>
            <td>Yes</td>
            <td>No</td>
        </tr>
        <tr>
            <td>Toast notifications</td>
            <td>Yes</td>
            <td>No</td>
        </tr>
        <tr>
            <td>SSH / basic terminals</td>
            <td>No</td>
            <td>Yes</td>
        </tr>
        <tr>
            <td>CI / headless</td>
            <td>No</td>
            <td>No (streaming plain text)</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="keyboard-shortcuts">Keyboard Shortcuts</h2>
<!-- ================================================================== -->

<p>
    The TUI mode supports the following keyboard shortcuts for efficient navigation:
</p>

<table>
    <thead>
        <tr>
            <th>Shortcut</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><kbd>Shift+Tab</kbd></td>
            <td>Cycle through agent modes (e.g. Code → Plan → Explore).</td>
        </tr>
        <tr>
            <td><kbd>Ctrl+L</kbd></td>
            <td>Force a full screen refresh.</td>
        </tr>
        <tr>
            <td><kbd>Page Up</kbd> / <kbd>Page Down</kbd></td>
            <td>Scroll through the conversation history.</td>
        </tr>
        <tr>
            <td><kbd>End</kbd></td>
            <td>Jump to the live (bottom) position in the conversation.</td>
        </tr>
        <tr>
            <td><kbd>Escape</kbd></td>
            <td>Cancel an in-progress completion or dismiss an overlay.</td>
        </tr>
        <tr>
            <td><kbd>Tab</kbd></td>
            <td>Accept the current autocomplete suggestion.</td>
        </tr>
    </tbody>
</table>

<!-- ================================================================== -->
<h2 id="subagent-rendering">Subagent Rendering</h2>
<!-- ================================================================== -->

<p>
    Subagents spawned by the orchestrator use a <strong>NullRenderer</strong> by default — they
    produce no terminal output of their own. Results are forwarded to the parent agent and displayed
    through the parent's renderer. Active subagents appear in the TUI's swarm dashboard
    (<kbd>Ctrl+A</kbd>) or the ANSI agent tree display.
</p>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';