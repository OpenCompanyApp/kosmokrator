<?php
$docTitle = 'Installation';
$docSlug = 'installation';
ob_start();
?>
<p class="lead">
    KosmoKrator is distributed in three forms: a self-contained static binary
    (no dependencies at all), a PHAR package (requires PHP 8.4+), and as a
    Composer-based source checkout. Choose the method that fits your environment
    and workflow.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="quick-install">Quick Install (Recommended)</h2>

<p>
    The install script auto-detects your OS and architecture, downloads the
    right static binary, and places it on your <code>$PATH</code>. No PHP
    required &mdash; everything is bundled.
</p>

<pre><code>curl -fsSL https://raw.githubusercontent.com/OpenCompanyApp/kosmokrator/main/install.sh | bash</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="static-binary">Static Binary (Manual)</h2>

<p>
    If you prefer to download manually, pick the binary for your platform.
    Each is a self-contained ~25 MB executable with the PHP runtime bundled.
</p>

<h3 id="binary-macos-arm">macOS &mdash; Apple Silicon (aarch64)</h3>

<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-macos-aarch64 \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>

<h3 id="binary-macos-intel">macOS &mdash; Intel (x86_64)</h3>

<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-macos-x86_64 \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>

<h3 id="binary-linux-x86">Linux &mdash; x86_64</h3>

<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-linux-x86_64 \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>

<h3 id="binary-linux-arm">Linux &mdash; ARM (aarch64)</h3>

<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-linux-aarch64 \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> If <code>/usr/local/bin</code> is not in your
        <code>$PATH</code>, or you prefer a user-local install, use
        <code>~/.local/bin/kosmokrator</code> instead. Make sure the directory
        exists and is on your <code>$PATH</code>.
    </p>
</div>

<p>
    After downloading, verify the binary works:
</p>

<pre><code>kosmokrator --version</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="phar-package">PHAR Package</h2>

<p>
    The PHAR (PHP Archive) build packages the entire application into a single
    <code>.phar</code> file of roughly 5 MB. It requires a working PHP 8.4+
    installation with a few extensions but does not need Composer or any vendor
    dependencies at runtime.
</p>

<h3 id="phar-requirements">Requirements</h3>

<ul>
    <li>PHP 8.4 or newer</li>
    <li>Extensions: <code>curl</code>, <code>mbstring</code>, <code>openssl</code>, <code>pdo_sqlite</code>, <code>pcntl</code>, <code>readline</code></li>
</ul>

<h3 id="phar-download">Download</h3>

<pre><code>sudo curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o /usr/local/bin/kosmokrator \
  && sudo chmod +x /usr/local/bin/kosmokrator</code></pre>

<p>
    Because the PHAR is a standard PHP archive, you can also run it directly
    with the PHP interpreter if you prefer not to place it on your
    <code>$PATH</code>:
</p>

<pre><code>php kosmokrator.phar</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> To check whether the required extensions are
        installed, run <code>php -m | grep -E 'curl|mbstring|openssl|pdo_sqlite|pcntl|readline'</code>.
        All six should appear in the output. On most systems they are
        included with the default PHP build; if any are missing, install them
        via your system package manager (e.g., <code>apt install php8.4-mbstring</code>
        on Debian/Ubuntu).
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="from-source">From Source</h2>

<p>
    Installing from source gives you the full development setup: you can
    modify the code, run the test suite, and build your own PHAR. This is the
    right choice if you plan to contribute or want to stay on the bleeding edge.
</p>

<h3 id="source-requirements">Requirements</h3>

<ul>
    <li>PHP 8.4 or newer</li>
    <li>Composer (2.x recommended)</li>
    <li>Extensions: <code>curl</code>, <code>mbstring</code>, <code>openssl</code>, <code>pdo_sqlite</code>, <code>pcntl</code>, <code>readline</code></li>
</ul>

<h3 id="source-clone">Clone and install</h3>

<pre><code>git clone https://github.com/OpenCompanyApp/kosmokrator.git
cd kosmokrator
composer install</code></pre>

<p>
    Once installed, run KosmoKrator directly from the repository:
</p>

<pre><code>bin/kosmokrator</code></pre>

<p>
    You can optionally symlink the entry point to a directory on your
    <code>$PATH</code> for convenience:
</p>

<pre><code>ln -s "$(pwd)/bin/kosmokrator" /usr/local/bin/kosmokrator</code></pre>

<h3 id="source-tests">Running the tests</h3>

<pre><code>php vendor/bin/phpunit</code></pre>

<h3 id="source-codestyle">Code style</h3>

<pre><code>php vendor/bin/pint</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="first-run-setup">First Run Setup</h2>

<p>
    The very first time you launch KosmoKrator, you need to configure at least
    one LLM provider. The built-in setup wizard walks you through the process
    interactively.
</p>

<pre><code>kosmokrator setup</code></pre>

<p>The setup wizard will guide you through three steps:</p>

<ol>
    <li>
        <strong>Provider selection</strong> &mdash; Choose from 20+ supported
        LLM providers (Anthropic, OpenAI, Google, Mistral, local endpoints,
        and many more). You can configure multiple providers and switch between
        them later.
    </li>
    <li>
        <strong>Model selection</strong> &mdash; Pick a default model from the
        provider's available options. You can override this per-session or
        per-depth later via the configuration file.
    </li>
    <li>
        <strong>API key entry</strong> &mdash; Enter the API key for your
        chosen provider. For OAuth-based providers (e.g., Codex), the wizard
        will open a browser-based device flow instead of prompting for a key.
        Keys are stored locally and never transmitted anywhere other than the
        provider's own API endpoint.
    </li>
</ol>

<p>
    All settings are written to <code>~/.kosmokrator/config.yaml</code>. You
    can edit this file directly at any time, or re-run
    <code>kosmokrator setup</code> to walk through the wizard again.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> You can also configure providers through the
        in-app settings dialog. Once KosmoKrator is running, use the
        <code>/settings</code> command to open the interactive settings
        workspace.
    </p>
</div>

<p>
    After setup completes, start KosmoKrator normally:
</p>

<pre><code>kosmokrator</code></pre>

<p>
    KosmoKrator will auto-detect your terminal capabilities and choose the
    best renderer &mdash; the full TUI if your terminal supports it, or the
    ANSI fallback otherwise.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="cli-options">CLI Options</h2>

<p>
    KosmoKrator accepts a number of command-line flags that control startup
    behavior. All flags are optional; the defaults are suitable for most
    interactive use.
</p>

<table>
    <thead>
        <tr>
            <th>Flag</th>
            <th>Description</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>--renderer=tui|ansi</code></td>
            <td>
                Force a specific renderer. By default KosmoKrator auto-detects
                terminal capabilities and uses the TUI renderer when available,
                falling back to pure ANSI output. Set this flag to override
                auto-detection.
            </td>
        </tr>
        <tr>
            <td><code>--no-animation</code></td>
            <td>
                Skip the animated intro sequence. Useful when piping output,
                embedding KosmoKrator in scripts, or when you simply prefer a
                faster startup.
            </td>
        </tr>
        <tr>
            <td><code>--resume</code></td>
            <td>
                Resume the most recent session. Your full conversation history,
                tool results, and context are restored so you can continue
                exactly where you left off.
            </td>
        </tr>
        <tr>
            <td><code>--session &lt;id&gt;</code></td>
            <td>
                Resume a specific session by its ID. Session IDs are displayed
                when a session is created and can be listed with the
                <code>/sessions</code> slash command.
            </td>
        </tr>
    </tbody>
</table>

<p>Example combining several flags:</p>

<pre><code>kosmokrator --renderer=ansi --no-animation --resume</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="updating">Updating</h2>

<p>
    KosmoKrator includes a built-in update mechanism. From a running session,
    use the slash command:
</p>

<pre><code>/update</code></pre>

<p>
    This checks the GitHub Releases page for a newer version and, if one is
    available, downloads and replaces the current binary or PHAR in place.
</p>

<p>
    Alternatively, you can update manually by re-downloading the binary or
    PHAR using the same <code>curl</code> commands shown above. The latest
    release URL always points to the most recent version.
</p>

<h3 id="updating-source">Updating a source installation</h3>

<p>
    If you installed from source, pull the latest changes and update
    dependencies:
</p>

<pre><code>cd kosmokrator
git pull
composer install</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="building-phar">Building a PHAR from Source</h2>

<p>
    If you are working from a source checkout and want to produce your own
    PHAR archive, use the <a href="https://github.com/box-project/box">Box</a>
    compiler. The repository includes a <code>box.json</code> configuration
    file that defines the build. Box is not included in the project's
    Composer dependencies &mdash; install it separately before compiling.
</p>

<pre><code># Install Box globally
composer global require humbug/box

# Compile the PHAR
box compile</code></pre>

<p>
    The compiled PHAR will be written to <code>builds/kosmokrator.phar</code>.
    You can then copy it to a location on your <code>$PATH</code> and use it
    exactly like the release PHAR.
</p>

<div class="tip">
    <p>
        <strong>Tip:</strong> Make sure <code>phar.readonly</code> is set to
        <code>Off</code> in your <code>php.ini</code> before compiling. Box
        will warn you if this setting is blocking PHAR creation.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="system-requirements">System Requirements</h2>

<p>
    The requirements depend on which installation method you choose. The
    static binary has the fewest requirements since it bundles everything
    it needs.
</p>

<table>
    <thead>
        <tr>
            <th>Requirement</th>
            <th>Static Binary</th>
            <th>PHAR</th>
            <th>From Source</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>PHP</td>
            <td>Not required</td>
            <td>8.4+</td>
            <td>8.4+</td>
        </tr>
        <tr>
            <td>Composer</td>
            <td>Not required</td>
            <td>Not required</td>
            <td>2.x</td>
        </tr>
        <tr>
            <td><code>curl</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td><code>mbstring</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td><code>openssl</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td><code>pdo_sqlite</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td><code>pcntl</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td><code>readline</code> extension</td>
            <td>Bundled</td>
            <td>Required</td>
            <td>Required</td>
        </tr>
        <tr>
            <td>Terminal</td>
            <td colspan="3">Any modern terminal emulator (iTerm2, Alacritty, Kitty, GNOME Terminal, Windows Terminal via WSL, etc.)</td>
        </tr>
        <tr>
            <td>Operating System</td>
            <td colspan="3">macOS (Apple Silicon or Intel) or Linux (x86_64 or aarch64)</td>
        </tr>
    </tbody>
</table>

<div class="tip">
    <p>
        <strong>Tip:</strong> For the best experience, use a terminal that
        supports 256 colors or true color. KosmoKrator's TUI renderer takes
        advantage of the full color palette for syntax highlighting, diffs,
        and the interactive dashboard.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="troubleshooting">Troubleshooting</h2>

<h3>Permission denied when writing to /usr/local/bin</h3>

<p>
    On macOS and some Linux distributions, <code>/usr/local/bin</code> requires
    elevated privileges. Either prefix the commands with <code>sudo</code>, or
    install to a user-local directory:
</p>

<pre><code>mkdir -p ~/.local/bin
# Then use ~/.local/bin/kosmokrator as the download target</code></pre>

<p>
    Make sure <code>~/.local/bin</code> is on your <code>$PATH</code> by
    adding this to your shell profile (<code>~/.zshrc</code>,
    <code>~/.bashrc</code>, etc.):
</p>

<pre><code>export PATH="$HOME/.local/bin:$PATH"</code></pre>

<h3>macOS Gatekeeper blocks the binary</h3>

<p>
    On macOS, the first time you run a downloaded binary, Gatekeeper may
    prevent execution. To allow it, run:
</p>

<pre><code>xattr -d com.apple.quarantine /usr/local/bin/kosmokrator</code></pre>

<h3>Missing PHP extensions (PHAR / source)</h3>

<p>
    If you see an error about a missing extension, install it through your
    system package manager. For example, on Debian or Ubuntu:
</p>

<pre><code>sudo apt install php8.4-curl php8.4-mbstring php8.4-openssl php8.4-pdo-sqlite php8.4-pcntl php8.4-readline</code></pre>

<p>
    On macOS with Homebrew, these extensions are typically included with the
    main PHP formula:
</p>

<pre><code>brew install php@8.4</code></pre>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';
