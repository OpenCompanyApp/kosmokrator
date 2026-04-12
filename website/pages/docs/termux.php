<?php
$docTitle = 'Termux (Android)';
$docSlug = 'termux';
ob_start();
?>
<p class="lead">
    KosmoKrator runs on Android via <a href="https://termux.dev">Termux</a>, a
    terminal emulator that provides a full Linux environment without root.
    This guide covers all three installation methods &mdash; static binary,
    PHAR, and source &mdash; with Termux-specific tips and workarounds.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="prerequisites">Prerequisites</h2>

<p>
    Install Termux from <a href="https://f-droid.org/packages/com.termux/">F-Droid</a>.
    The Google Play version is outdated and no longer receives updates &mdash;
    always use the F-Droid release.
</p>

<p>Once Termux is open, update the package index:</p>

<pre><code>pkg update && pkg upgrade -y</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="static-binary">Static Binary (Recommended)</h2>

<p>
    The fastest path. Most Android devices use ARM, so download the
    <code>aarch64</code> binary. No PHP installation required.
</p>

<pre><code>pkg install -y curl

curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator-linux-aarch64 \
  -o $PREFIX/bin/kosmokrator \
  && chmod +x $PREFIX/bin/kosmokrator</code></pre>

<p>Verify:</p>

<pre><code>kosmokrator --version</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> Termux uses <code>$PREFIX/bin</code> (typically
        <code>/data/data/com.termux/files/usr/bin</code>) instead of
        <code>/usr/local/bin</code>. There is no <code>sudo</code> &mdash;
        Termux packages are installed in user space.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="phar-package">PHAR Package</h2>

<p>
    If you prefer the PHAR, install PHP first:
</p>

<pre><code>pkg install -y php curl

curl -fSL https://github.com/OpenCompanyApp/kosmokrator/releases/latest/download/kosmokrator.phar \
  -o $PREFIX/bin/kosmokrator \
  && chmod +x $PREFIX/bin/kosmokrator</code></pre>

<p>
    Termux's PHP package ships with <code>curl</code>, <code>mbstring</code>,
    <code>openssl</code>, and <code>pdo_sqlite</code> built in. Verify with:
</p>

<pre><code>php -m | grep -E 'curl|mbstring|openssl|pdo_sqlite|pcntl|readline'</code></pre>

<p>
    If any extensions are missing, install the matching Termux package:
</p>

<pre><code>pkg install -y php-pdo php-sqlite</code></pre>

<!-- ------------------------------------------------------------------ -->
<h2 id="from-source">From Source</h2>

<p>
    A source checkout gives you the full development environment.
</p>

<h3 id="source-packages">Install packages</h3>

<pre><code>pkg install -y php git composer openssh</code></pre>

<h3 id="source-clone">Clone and install</h3>

<pre><code>git clone https://github.com/OpenCompanyApp/kosmokrator.git
cd kosmokrator
composer install</code></pre>

<p>Run directly from the checkout:</p>

<pre><code>php bin/kosmokrator --renderer=ansi</code></pre>

<p>
    Or symlink for convenience:
</p>

<pre><code>ln -s "$(pwd)/bin/kosmokrator" $PREFIX/bin/kosmokrator</code></pre>

<div class="tip">
    <p>
        <strong>Tip:</strong> If Composer runs out of memory, set
        <code>COMPOSER_MEMORY_LIMIT=-1 composer install</code> to remove the cap.
    </p>
</div>

<!-- ------------------------------------------------------------------ -->
<h2 id="first-run">First Run</h2>

<p>
    Run the setup wizard to configure your LLM provider:
</p>

<pre><code>kosmokrator setup</code></pre>

<p>
    Then start a session. Use ANSI mode for the most reliable experience:
</p>

<pre><code>kosmokrator --renderer=ansi</code></pre>

<p>
    The ANSI renderer works well in Termux and does not depend on
    <code>stty</code> or <code>pcntl</code> features that may behave
    differently on Android.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="renderer">Choosing a Renderer</h2>

<table>
    <thead>
        <tr>
            <th>Renderer</th>
            <th>Termux Support</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>ansi</code></td>
            <td>Recommended</td>
            <td>Pure escape codes, works everywhere, readline input</td>
        </tr>
        <tr>
            <td><code>tui</code></td>
            <td>Experimental</td>
            <td>
                Requires <code>stty</code> and a terminal that correctly
                reports size. May work in Termux but can be glitchy on
                some devices.
            </td>
        </tr>
    </tbody>
</table>

<p>
    If you want to try the TUI renderer, launch with
    <code>kosmokrator --renderer=tui</code> and fall back to ANSI if you
    encounter display issues.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="keyboard">Keyboard Tips</h2>

<p>
    A hardware keyboard or
    <a href="https://play.google.com/store/apps/details?id=org.pocketworkstation.pckeyboard">Hacker's Keyboard</a>
    makes the experience much better. If you are using the default
    Termux soft keyboard:
</p>

<ul>
    <li>Swipe the extra-keys bar left/right to access <kbd>Ctrl</kbd>, <kbd>Tab</kbd>, <kbd>Esc</kbd>, and arrow keys</li>
    <li>Long-press the volume-down key while typing for <kbd>Ctrl</kbd> combos</li>
    <li><code>Ctrl+C</code> cancels a running tool, <code>Ctrl+D</code> exits the session</li>
</ul>

<!-- ------------------------------------------------------------------ -->
<h2 id="storage">Storage &amp; Sessions</h2>

<p>
    By default, KosmoKrator stores its SQLite database and configuration in
    <code>~/.kosmokrator/</code>. In Termux, <code>~</code> resolves to
    <code>/data/data/com.termux/files/home</code>, which is private to the
    Termux app.
</p>

<p>
    If you need to access project files on shared storage (Downloads,
    Documents, etc.), grant Termux storage access first:
</p>

<pre><code>termux-setup-storage</code></pre>

<p>
    This creates <code>~/storage/</code> with symlinks to shared directories.
    You can then <code>cd ~/storage/downloads</code> to work on files there.
</p>

<!-- ------------------------------------------------------------------ -->
<h2 id="troubleshooting">Troubleshooting</h2>

<h3>stty: stdin not a terminal</h3>

<p>
    This warning is harmless. The ANSI renderer handles it gracefully. If
    you see persistent issues, force ANSI mode with <code>--renderer=ansi</code>.
</p>

<h3>SQLite errors or permission denied</h3>

<p>
    Ensure the storage directory is writable:
</p>

<pre><code>chmod -R 775 ~/.kosmokrator/</code></pre>

<p>
    If running from source, also check the project's <code>storage/</code>
    directory:
</p>

<pre><code>chmod -R 775 storage/</code></pre>

<h3>Composer memory errors</h3>

<p>
    Termux's default memory limit may be too low for Composer on some devices.
    Override it:
</p>

<pre><code>COMPOSER_MEMORY_LIMIT=-1 composer install</code></pre>

<h3>Process killed by Android</h3>

<p>
    Android may kill background Termux processes to reclaim memory. To keep
    sessions alive:
</p>

<ul>
    <li>Run <code>termux-wake-lock</code> to acquire a wake lock</li>
    <li>
        In Android settings, disable battery optimization for the Termux app
    </li>
    <li>
        Use <a href="https://wiki.termux.com/wiki/Termux:API">Termux:API</a>
        with <code>termux-notification</code> for persistent foreground
        notifications
    </li>
</ul>

<h3>pcntl extension not available</h3>

<p>
    The <code>pcntl</code> extension may not be available in Termux's PHP
    build. KosmoKrator's ANSI renderer works without it &mdash; Revolt's
    event loop falls back to <code>stream_select</code>. You can safely
    ignore <code>pcntl</code>-related warnings when using ANSI mode.
</p>

<?php
$docContent = ob_get_clean();
include __DIR__ . '/../_docs-layout.php';
