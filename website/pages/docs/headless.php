<?php
$docTitle = 'Headless Mode';
$docSlug = 'headless';
ob_start();
?>
<p class="lead">
    Run KosmoKrator non-interactively for CI/CD pipelines, shell scripts, git hooks, and automated workflows.
    Pass a prompt, get output, exit. No TTY required.
</p>

<!-- ================================================================== -->
<h2 id="quick-start">Quick Start</h2>
<!-- ================================================================== -->

<p>The three ways to invoke headless mode:</p>

<pre><code># Positional prompt — the simplest invocation
kosmokrator "fix the off-by-one error in src/Math.php"

# Explicit -p flag
kosmokrator -p "list all TODO comments in the codebase"

# Stdin pipe
echo "explain this error" | kosmokrator</code></pre>

<p>All three produce the same result: the agent runs to completion, writes the final response to <strong>stdout</strong>, and exits with code <code>0</code> on success. Progress and diagnostics go to <strong>stderr</strong>, so <code>result=$(kosmokrator -p "task")</code> captures only the response.</p>

<div class="tip"><p><strong>Tip:</strong> Combine stdin with a positional prompt — stdin is appended after the prompt. Great for injecting file contents into a task:</p></div>

<pre><code>cat error.log | kosmokrator -p "explain this error and suggest a fix"</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="headless-integrations">Headless Integrations</h3>
<!-- ------------------------------------------------------------------ -->

<p>
    If you do not need an agent turn and only want to call external tools such as Plane, ClickUp,
    or other OpenCompany integration packages, use the dedicated integrations CLI instead:
</p>

<pre><code># Discover configured providers
kosmokrator integrations:status --json

# Read the schema for one integration function
kosmokrator integrations:schema plane.list_issues

# Call an integration directly
kosmokrator integrations:call plane.list_issues \
  --workspace-slug=kosmokrator \
  --project-id=PROJECT_UUID \
  --json

# Run a multi-step Lua workflow against configured integrations
kosmokrator integrations:lua workflow.lua --json</code></pre>

<p>
    This integration surface is designed for scripts and other coding CLIs. It is documented in
    detail in <a href="/docs/integrations">Integrations CLI</a>.
</p>

<!-- ================================================================== -->
<h2 id="output-formats">Output Formats</h2>
<!-- ================================================================== -->

<p>Control the output format with <code>-o</code> / <code>--output-format</code>:</p>

<pre><code># Human-readable text (default)
kosmokrator -p "list the files in src/"

# Single JSON blob at completion
kosmokrator -p -o json "what does the AgentLoop class do?"

# Streaming NDJSON events as they happen
kosmokrator -p -o stream-json "refactor the auth module"</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="text-format">Text</h3>
<!-- ------------------------------------------------------------------ -->

<p>The default. Final response on stdout, progress on stderr:</p>

<pre><code>$ kosmokrator -p "how many PHP files are in src/?"

  [Working]                           ← stderr (dimmed)
  → bash(command: find src/ -name "*.php" | wc -l)  ← stderr
  ✓ bash: 142                         ← stderr
  tokens: 1234→567  cost: $0.03       ← stderr

There are 142 PHP files in the src/ directory.              ← stdout</code></pre>

<div class="tip"><p><strong>Tip:</strong> Only stdout contains the result. Stderr is for human eyes only. This makes text mode ideal for scripting:</p></div>

<pre><code># Capture just the result
result=$(kosmokrator -p "generate a migration for users table")

# Show progress live, capture result
kosmokrator -p "run the test suite" 2>/dev/null</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="json-format">JSON</h3>
<!-- ------------------------------------------------------------------ -->

<p>A single structured JSON blob written to stdout when the agent finishes. Useful for programmatic consumption:</p>

<pre><code>$ kosmokrator -p -o json "list the routes"
{
    "type": "result",
    "text": "The application defines these routes:\n1. GET / ...",
    "duration_ms": 4500,
    "turns": 0,
    "usage": {
        "tokens_in": 1234,
        "tokens_out": 567
    },
    "errors": [],
    "tool_calls": [
        {"name": "bash", "args": {"command": "php artisan route:list"}, "output": "...", "success": true}
    ]
}</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="stream-json-format">Stream JSON</h3>
<!-- ------------------------------------------------------------------ -->

<p>Emits <a href="https://ndjson.org/">NDJSON</a> events to stdout as they happen. Each line is a complete JSON object. Designed for IDE integrations, real-time dashboards, and long-running agent monitoring:</p>

<pre><code>$ kosmokrator -p -o stream-json "analyze the codebase"
{"type":"user_message","text":"analyze the codebase","timestamp":1712570000000}
{"type":"phase","phase":"thinking","timestamp":1712570000100}
{"type":"text_delta","delta":"I'll start by ","timestamp":1712570005000}
{"type":"text_delta","delta":"examining the directory structure.","timestamp":1712570005100}
{"type":"tool_call","name":"bash","args":{"command":"find src/ -type f | head -30"},"timestamp":1712570006000}
{"type":"tool_result","name":"bash","output":"src/Agent/AgentLoop.php\n...","success":true,"timestamp":1712570007000}
{"type":"result","text":"The codebase follows...","duration_ms":8000,"turns":3,"usage":{"tokens_in":2345,"tokens_out":890},"timestamp":1712570010000}</code></pre>

<p>Event types:</p>

<table>
<thead>
<tr><th>Event</th><th>Description</th></tr>
</thead>
<tbody>
<tr><td><code>user_message</code></td><td>The prompt sent to the agent</td></tr>
<tr><td><code>phase</code></td><td>Agent phase transition (thinking, working, idle)</td></tr>
<tr><td><code>text_delta</code></td><td>Incremental text from the LLM response</td></tr>
<tr><td><code>reasoning</code></td><td>Extended thinking content (if supported by model)</td></tr>
<tr><td><code>tool_call</code></td><td>A tool is being invoked with name and arguments</td></tr>
<tr><td><code>tool_result</code></td><td>Tool execution result with success status</td></tr>
<tr><td><code>subagent_spawn</code></td><td>A child agent was spawned</td></tr>
<tr><td><code>subagent_batch</code></td><td>Child agent(s) completed</td></tr>
<tr><td><code>error</code></td><td>An error occurred during execution</td></tr>
<tr><td><code>result</code></td><td>Final result with usage stats and duration</td></tr>
</tbody>
</table>

<!-- ================================================================== -->
<h2 id="cli-reference">CLI Reference</h2>
<!-- ================================================================== -->

<p>All headless-related flags. Combine freely:</p>

<table>
<thead>
<tr><th>Flag</th><th>Alias</th><th>Description</th></tr>
</thead>
<tbody>
<tr><td><code>[prompt]</code></td><td>&mdash;</td><td>Positional task prompt (enables headless mode)</td></tr>
<tr><td><code>--print</code></td><td><code>-p</code></td><td>Explicit headless mode</td></tr>
<tr><td><code>--output-format</code></td><td><code>-o</code></td><td>Output format: <code>text</code>, <code>json</code>, <code>stream-json</code></td></tr>
<tr><td><code>--model</code></td><td><code>-m</code></td><td>Override model for this run</td></tr>
<tr><td><code>--mode</code></td><td>&mdash;</td><td>Agent mode: <code>edit</code>, <code>plan</code>, <code>ask</code></td></tr>
<tr><td><code>--yolo</code></td><td>&mdash;</td><td>Skip all permission checks (alias for <code>--permission-mode prometheus</code>)</td></tr>
<tr><td><code>--permission-mode</code></td><td>&mdash;</td><td>Permission mode: <code>guardian</code>, <code>argus</code>, <code>prometheus</code></td></tr>
<tr><td><code>--max-turns</code></td><td><code>-t</code></td><td>Maximum agentic turns (LLM call cycles)</td></tr>
<tr><td><code>--timeout</code></td><td>&mdash;</td><td>Maximum runtime in seconds</td></tr>
<tr><td><code>--continue</code></td><td><code>-c</code></td><td>Continue the most recent session</td></tr>
<tr><td><code>--resume</code></td><td>&mdash;</td><td>Resume last session (same as <code>--continue</code>)</td></tr>
<tr><td><code>--session</code></td><td>&mdash;</td><td>Resume a specific session by ID</td></tr>
<tr><td><code>--system-prompt</code></td><td>&mdash;</td><td>Replace the system prompt entirely</td></tr>
<tr><td><code>--append-system-prompt</code></td><td>&mdash;</td><td>Append to the default system prompt</td></tr>
<tr><td><code>--no-session</code></td><td>&mdash;</td><td>Don't persist the session to disk</td></tr>
</tbody>
</table>

<!-- ================================================================== -->
<h2 id="permissions">Permissions in Headless Mode</h2>
<!-- ================================================================== -->

<p>In headless mode, all tool calls are auto-approved by default &mdash; there's no interactive prompt to ask for permission. This matches the behavior users expect from other coding agents' non-interactive modes.</p>

<p>Use <code>--yolo</code> to explicitly opt into full auto-pilot:</p>

<pre><code># Skip all permission checks
kosmokrator -p --yolo "run the full test suite and fix any failures"</code></pre>

<p>Or use <code>--permission-mode</code> for explicit control:</p>

<pre><code># Use Guardian mode (auto-approve safe tools, deny risky ones)
kosmokrator -p --permission-mode guardian "add type hints to src/Utils.php"

# Use Argus mode (deny tools that would normally ask)
kosmokrator -p --permission-mode argus "what files are in the project?"</code></pre>

<p>See <a href="/docs/permissions">Permissions</a> for details on each mode.</p>

<!-- ================================================================== -->
<h2 id="guardrails">Guardrails</h2>
<!-- ================================================================== -->

<p>For autonomous runs, protect against runaway agents with <code>--max-turns</code> and <code>--timeout</code>:</p>

<pre><code># Maximum 10 LLM call cycles
kosmokrator -p --max-turns 10 "refactor the database layer"

# Maximum 5 minutes
kosmokrator -p --timeout 300 "implement user authentication"

# Combine both
kosmokrator -p --max-turns 20 --timeout 600 "rewrite the API module"</code></pre>

<div class="tip"><p><strong>Tip:</strong> One "turn" is one LLM call cycle. A turn that triggers tool calls counts as one turn &mdash; the tool execution and follow-up LLM call is the next turn.</p></div>

<!-- ================================================================== -->
<h2 id="sessions">Session Management</h2>
<!-- ================================================================== -->

<p>Headless runs create sessions by default, just like interactive mode. This means you can resume them later:</p>

<pre><code># Run a task (creates a session)
kosmokrator -p "analyze the authentication flow"

# Continue that session later
kosmokrator -p -c "now implement the suggested improvements"

# Resume a specific session by ID
kosmokrator -p --session abc123 "what was my last question about?"</code></pre>

<p>Use <code>--no-session</code> for ephemeral runs where you don't need session history:</p>

<pre><code># Quick one-off question, no session persisted
kosmokrator -p --no-session "what does this regex do? /([A-Z])\w+/"</code></pre>

<!-- ================================================================== -->
<h2 id="model-selection">Model Selection</h2>
<!-- ================================================================== -->

<p>Override the configured model for a single run:</p>

<pre><code># Use a specific model
kosmokrator -p -m sonnet "quick code review of src/Http/"

# Use a cheaper model for simple tasks
kosmokrator -p -m haiku "generate a .gitignore for a PHP project"</code></pre>

<p>The model name matches your provider's model identifier. See <a href="/docs/providers">Providers</a> for available models.</p>

<!-- ================================================================== -->
<h2 id="agent-modes">Agent Modes</h2>
<!-- ================================================================== -->

<p>Control which tools the agent can use:</p>

<pre><code># Full access (default) — all tools available
kosmokrator -p --mode edit "fix the login bug"

# Read-only — analyze without modifying files
kosmokrator -p --mode plan "design a caching strategy for the API"

# Ask mode — read files and run bash, but no file writes
kosmokrator -p --mode ask "what does the QueueWorker do?"</code></pre>

<table>
<thead>
<tr><th>Mode</th><th>File Read</th><th>File Write</th><th>Bash</th><th>Subagents</th></tr>
</thead>
<tbody>
<tr><td><code>edit</code> (default)</td><td>&check;</td><td>&check;</td><td>&check;</td><td>&check;</td></tr>
<tr><td><code>plan</code></td><td>&check;</td><td>&times;</td><td>&check;</td><td>&check;</td></tr>
<tr><td><code>ask</code></td><td>&check;</td><td>&times;</td><td>&check;</td><td>&check;</td></tr>
</tbody>
</table>

<!-- ================================================================== -->
<h2 id="system-prompt">System Prompt Control</h2>
<!-- ================================================================== -->

<p>Customize the agent's behavior by modifying the system prompt:</p>

<pre><code># Append instructions to the default prompt
kosmokrator -p --append-system-prompt "Always use PSR-12 coding style" "refactor src/"

# Replace the entire system prompt
kosmokrator -p --system-prompt "You are a security auditor. Find vulnerabilities." "audit src/Auth/"</code></pre>

<!-- ================================================================== -->
<h2 id="exit-codes">Exit Codes</h2>
<!-- ================================================================== -->

<table>
<thead>
<tr><th>Code</th><th>Meaning</th></tr>
</thead>
<tbody>
<tr><td><code>0</code></td><td>Success &mdash; agent completed the task</td></tr>
<tr><td><code>1</code></td><td>Error &mdash; agent encountered an error or returned an error response</td></tr>
<tr><td><code>2</code></td><td>Limit exceeded &mdash; max turns or timeout reached, or invalid option value</td></tr>
<tr><td><code>130</code></td><td>Cancelled &mdash; interrupted by SIGINT (Ctrl+C)</td></tr>
<tr><td><code>143</code></td><td>Cancelled &mdash; interrupted by SIGTERM</td></tr>
</tbody>
</table>

<pre><code># Use in shell scripts
kosmokrator -p "run tests" 
if [ $? -eq 0 ]; then
    echo "Tests passed!"
elif [ $? -eq 2 ]; then
    echo "Agent hit a guardrail limit"
fi</code></pre>

<!-- ================================================================== -->
<h2 id="ci-cd">CI/CD Integration</h2>
<!-- ================================================================== -->

<!-- ------------------------------------------------------------------ -->
<h3 id="github-actions">GitHub Actions</h3>
<!-- ------------------------------------------------------------------ -->

<pre><code>name: AI Code Review
on: [pull_request]
jobs:
  review:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup KosmoKrator
        run: |
          curl -sSL https://kosmokrator.dev/install.sh | bash
          kosmokrator setup --provider anthropic --api-key ${{ secrets.ANTHROPIC_API_KEY }}

      - name: Run AI Review
        run: |
          kosmokrator -p --no-session --max-turns 5 \
            "Review the changed files in this PR. Focus on security, 
             performance, and code style. Output a summary." \
            2>review-progress.txt >review-result.txt

      - name: Post Review Comment
        if: success()
        uses: actions/github-script@v7
        with:
          script: |
            const fs = require('fs');
            const review = fs.readFileSync('review-result.txt', 'utf8');
            github.rest.issues.createComment({
              ...context.repo,
              issue_number: context.issue.number,
              body: review
            });</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="git-hooks">Git Hooks</h3>
<!-- ------------------------------------------------------------------ -->

<pre><code>#!/bin/bash
# .git/hooks/pre-commit — AI-assisted commit message validation
# Requires: kosmokrator in PATH

staged=$(git diff --cached --name-only)
if [ -z "$staged" ]; then exit 0; fi

issues=$(kosmokrator -p --no-session --max-turns 3 --timeout 30 \
  "Check these staged files for obvious bugs, debugging leftovers 
   (dd, dump, console.log), and TODO comments that should block commit.
   Files: $staged
   Output ONLY 'PASS' if everything looks good, or list the issues." \
  2>/dev/null)

if [ "$issues" != "PASS" ]; then
  echo "AI pre-commit check found issues:"
  echo "$issues"
  exit 1
fi</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="makefile">Makefile Integration</h3>
<!-- ------------------------------------------------------------------ -->

<pre><code># Makefile
.PHONY: ai-review ai-docs ai-test

ai-review:
	@kosmokrator -p --max-turns 10 --timeout 300 \
		"Review the codebase for security issues, performance problems, \
		 and code style violations. Prioritize actual bugs over style nits."

ai-docs:
	@kosmokrator -p --mode plan \
		"Generate API documentation for all public methods in src/. \
		 Output markdown suitable for a docs site."

ai-test:
	@kosmokrator -p --yolo \
		"Run the test suite. If any tests fail, fix them and re-run."</code></pre>

<!-- ================================================================== -->
<h2 id="scripting-patterns">Scripting Patterns</h2>
<!-- ================================================================== -->

<!-- ------------------------------------------------------------------ -->
<h3 id="batch-processing">Batch Processing</h3>
<!-- ------------------------------------------------------------------ -->

<p>Process multiple files or tasks sequentially:</p>

<pre><code>#!/bin/bash
# Add type hints to all PHP files in a directory
for file in src/Service/*.php; do
  echo "Processing $file..."
  kosmokrator -p --no-session --max-turns 3 \
    "Add strict type declarations and parameter type hints to $file. \
     Only modify the file if types are missing."
done</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="json-parsing">JSON Output Parsing</h3>
<!-- ------------------------------------------------------------------ -->

<pre><code>#!/bin/bash
# Get structured results with jq
result=$(kosmokrator -p -o json --no-session "list all PHP classes in src/")
echo "Tokens used: $(echo "$result" | jq '.usage.tokens_in') in, $(echo "$result" | jq '.usage.tokens_out') out"
echo "Duration: $(echo "$result" | jq '.duration_ms')ms"
echo "Tool calls: $(echo "$result" | jq '.tool_calls | length')"
echo "Response:"
echo "$result" | jq -r '.text'</code></pre>

<!-- ------------------------------------------------------------------ -->
<h3 id="streaming-pipeline">Streaming Pipeline</h3>
<!-- ------------------------------------------------------------------ -->

<pre><code>#!/usr/bin/env python3
"""Real-time agent monitoring via stream-json."""
import sys, json, subprocess

proc = subprocess.Popen(
    ['kosmokrator', '-p', '-o', 'stream-json', 'refactor the auth module'],
    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
    text=True
)

for line in proc.stdout:
    event = json.loads(line)
    match event['type']:
        case 'tool_call':
            print(f"  → {event['name']}({list(event['args'].keys())[0]}: ...)")
        case 'tool_result':
            status = '✓' if event['success'] else '✗'
            print(f"  {status} {event['name']}")
        case 'result':
            print(f"\nDone in {event['duration_ms']}ms")
            print(event['text'])

proc.wait()
sys.exit(proc.returncode)</code></pre>

<!-- ================================================================== -->
<h2 id="comparison">Comparison with Interactive Mode</h2>
<!-- ================================================================== -->

<table>
<thead>
<tr><th>Feature</th><th>Interactive</th><th>Headless</th></tr>
</thead>
<tbody>
<tr><td>Input method</td><td>REPL prompt</td><td>CLI arg, stdin, or <code>-p</code></td></tr>
<tr><td>Output</td><td>TUI / ANSI renderer</td><td>stdout (text, JSON, stream-json)</td></tr>
<tr><td>Tool permissions</td><td>Interactive prompts</td><td>Auto-approved</td></tr>
<tr><td>Plan approval</td><td>Interactive dialog</td><td>Plans output but not auto-implemented</td></tr>
<tr><td>Ask user/choice</td><td>Interactive prompts</td><td>Returns empty/dismissed</td></tr>
<tr><td>Slash commands</td><td>Available</td><td>Not available</td></tr>
<tr><td>Session persistence</td><td>Yes</td><td>Yes (disable with <code>--no-session</code>)</td></tr>
<tr><td>Session resume</td><td><code>--resume</code></td><td><code>--continue</code>, <code>--resume</code>, <code>--session</code></td></tr>
<tr><td>Subagents</td><td>Full support</td><td>Full support</td></tr>
<tr><td>Stuck detection</td><td>No</td><td>Yes (auto-nudge and force-return)</td></tr>
<tr><td>Max turns / timeout</td><td>Not available</td><td><code>--max-turns</code>, <code>--timeout</code></td></tr>
</tbody>
</table>

<!-- ================================================================== -->
<h2 id="migrating">Migrating from Other Agents</h2>
<!-- ================================================================== -->

<p>If you're coming from another coding agent, here's how the headless flags map:</p>

<table>
<thead>
<tr><th>You're used to</th><th>KosmoKrator equivalent</th></tr>
</thead>
<tbody>
<tr><td>Claude Code <code>-p</code></td><td><code>kosmokrator -p</code> (identical)</td></tr>
<tr><td>Claude Code <code>--output-format json</code></td><td><code>kosmokrator -o json</code></td></tr>
<tr><td>Claude Code <code>--output-format stream-json</code></td><td><code>kosmokrator -o stream-json</code></td></tr>
<tr><td>Claude Code <code>--dangerously-skip-permissions</code></td><td><code>kosmokrator --yolo</code></td></tr>
<tr><td>Claude Code <code>--continue</code></td><td><code>kosmokrator -c</code> (identical)</td></tr>
<tr><td>Claude Code <code>--max-turns</code></td><td><code>kosmokrator --max-turns</code> (identical)</td></tr>
<tr><td>Claude Code <code>--model</code></td><td><code>kosmokrator -m</code></td></tr>
<tr><td>Codex CLI <code>codex exec "task"</code></td><td><code>kosmokrator "task"</code></td></tr>
<tr><td>Codex CLI <code>--full-auto</code></td><td><code>kosmokrator --yolo</code></td></tr>
<tr><td>Codex CLI <code>-q</code></td><td><code>kosmokrator -p</code></td></tr>
<tr><td>Aider <code>--message "task"</code></td><td><code>kosmokrator -p "task"</code></td></tr>
<tr><td>Aider <code>--yes-always</code></td><td><code>kosmokrator --yolo</code></td></tr>
<tr><td>Goose <code>goose run -t "task"</code></td><td><code>kosmokrator "task"</code></td></tr>
<tr><td>OpenCode <code>opencode run "task"</code></td><td><code>kosmokrator "task"</code></td></tr>
</tbody>
</table>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';
