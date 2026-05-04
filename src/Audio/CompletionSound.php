<?php

declare(strict_types=1);

namespace Kosmokrator\Audio;

use Kosmokrator\LLM\LlmClientInterface;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Psr\Log\LoggerInterface;

/**
 * Composes and plays a short musical piece after each agent completion.
 *
 * The entire flow — LLM composition call, script validation, Python playback — runs
 * in a fully async background PHP process. The main agent REPL is never blocked.
 *
 * Each running KosmoKrator instance gets a distinct instrument (voice) based on the
 * session ID, so multiple concurrent instances are distinguishable by ear.
 */
final class CompletionSound
{
    private const INSTRUMENTS = [
        0,   // Acoustic Grand Piano
        11,  // Vibraphone
        24,  // Acoustic Guitar (nylon)
        41,  // Violin
        46,  // Orchestral Harp
        56,  // Trumpet
        73,  // Flute
        98,  // Crystal Pad (synth)
        105, // Banjo
        112, // Fiddle
    ];

    private const COMPOSITION_SYSTEM_PROMPT = <<<'PROMPT'
Compose a short Python MIDI script (3-8 seconds) that sonifies a coding task outcome.
Success=fanfare(major,bright). Error=minor,descending. Tests passing=upbeat consonant.
Tests failing=interrupted drop. Simple answer=3-4 notes. Complex=rich harmony.

Technical:
- Use: from midiutil import MIDIFile; math,os,tempfile,subprocess
- Soundfont: os.path.expanduser("SOUNDFONT_PLACEHOLDER")
- Instrument: MIDI program INSTRUMENT_PLACEHOLDER
- Use midi.addNote(track,channel,pitch,beat,duration,velocity). Track 0 melody, track 1 bass.
- Tempo: 80-140 BPM. Pitch: 40-96. Velocity: 60-120.
- Playback: subprocess.Popen(['fluidsynth','-q','-a','coreaudio','-n','-i','-l',sf2_path,midi_path]); proc.wait(timeout=15)
- Output ONLY Python code. No markdown, no explanation.
PROMPT;

    private bool $enabled;

    private string $soundfont;

    private int $maxDuration;

    private int $instrument;

    private int $maxRetries;

    private int $llmTimeoutSeconds;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly LoggerInterface $log,
        string $sessionId,
        ?bool $enabled = null,
        ?string $soundfont = null,
        int $maxDuration = 8,
        int $maxRetries = 1,
        int $llmTimeoutSeconds = 60,
    ) {
        $this->enabled = $enabled ?? false;
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $this->soundfont = $soundfont ?? $home.'/.kosmo/soundfonts/FluidR3_GM.sf2';
        $this->maxDuration = $maxDuration;
        $this->maxRetries = $maxRetries;
        $this->llmTimeoutSeconds = max(1, $llmTimeoutSeconds);

        // Assign a deterministic instrument based on session ID
        $hash = crc32($sessionId);
        $this->instrument = self::INSTRUMENTS[abs($hash) % count(self::INSTRUMENTS)];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compose and play a completion sound — fully async, never blocks the caller.
     *
     * Spawns a background PHP process that handles everything:
     * LLM call → validation → retries → Python script → MIDI playback.
     * Returns immediately; the main REPL continues unaffected.
     */
    public function play(string $message, int $roundCount = 1, string $projectName = ''): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! file_exists($this->soundfont)) {
            $this->log->warning('Completion sound skipped: soundfont not found', [
                'path' => $this->soundfont,
            ]);
            error_log("[CompletionSound] Soundfont not found: {$this->soundfont}");

            return;
        }

        $this->log->info('Completion sound: dispatching to background process', [
            'message_length' => strlen($message),
            'round_count' => $roundCount,
            'instrument' => $this->instrument,
            'instrument_name' => $this->getInstrumentName(),
        ]);

        $this->spawnBackgroundWorker($message, $roundCount, $projectName);
    }

    /**
     * Spawn a background PHP process that handles the full compose-and-play flow.
     *
     * The worker is a standalone PHP script invoked via CLI that bootstraps
     * the container, calls the LLM, validates, retries, and plays the result.
     * Uses the same autoloader and container as the main process.
     */
    private function spawnBackgroundWorker(string $message, int $roundCount, string $projectName): void
    {
        $payload = base64_encode(json_encode([
            'message' => $message,
            'round_count' => $roundCount,
            'project_name' => $projectName,
            'soundfont' => $this->soundfont,
            'instrument' => $this->instrument,
            'max_retries' => $this->maxRetries,
            'max_duration' => $this->maxDuration,
            'llm_timeout' => $this->llmTimeoutSeconds,
            'log_dir' => dirname(__DIR__, 2).'/storage/logs',
        ], JSON_UNESCAPED_UNICODE));

        $phpBinary = PHP_BINARY;
        $workerScript = dirname(__DIR__, 2).'/src/Audio/compose_worker.php';

        if (! file_exists($workerScript)) {
            $this->log->error('Completion sound: worker script not found', ['path' => $workerScript]);
            error_log("[CompletionSound] Worker script not found: {$workerScript}");

            return;
        }

        $command = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($workerScript),
            escapeshellarg($payload),
        );

        $this->log->info('Completion sound: spawning background worker', [
            'php' => $phpBinary,
            'worker' => $workerScript,
            'payload_size' => strlen($payload),
        ]);
        error_log("[CompletionSound] Spawning: {$command}");

        exec($command);
    }

    /**
     * Run the compose-and-play flow (called by the background worker).
     *
     * This method performs the synchronous LLM call, validation, and retry logic.
     * It is intentionally never called from the main process — only from the worker.
     */
    public function composeAndPlay(string $message, int $roundCount, string $projectName): void
    {
        $script = $this->composeWithRetries($message, $roundCount, $projectName);

        if ($script === null) {
            $this->log->warning('Completion sound: all composition attempts failed');

            return;
        }

        $this->spawnPlayback($script);
    }

    /**
     * Attempt LLM composition with retries on failure.
     */
    private function composeWithRetries(string $message, int $roundCount, string $projectName): ?string
    {
        $prompt = $this->buildPrompt($message, $roundCount, $projectName);
        $lastError = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->log->info('Completion sound: retrying composition', ['attempt' => $attempt]);
            }

            try {
                $script = $this->callLlm($prompt);

                if ($this->validateScript($script)) {
                    $this->log->info('Completion sound: script composed successfully', [
                        'attempt' => $attempt,
                        'script_length' => strlen($script),
                    ]);

                    return $script;
                }

                $lastError = 'Script validation failed (missing midiutil or forbidden imports)';
                $this->log->warning('Completion sound: script validation failed', ['attempt' => $attempt]);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->log->warning('Completion sound: LLM call failed', [
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);

                if (str_contains(mb_strtolower($lastError), 'timed out')) {
                    break;
                }
            }
        }

        $this->log->error('Completion sound: all attempts exhausted', [
            'last_error' => $lastError,
            'attempts' => $this->maxRetries + 1,
        ]);

        $fallback = $this->buildFallbackScript($message, $roundCount, $projectName);
        if (! $this->validateScript($fallback)) {
            $this->log->error('Completion sound: fallback script failed validation');

            return null;
        }

        $this->log->warning('Completion sound: using fallback composition', [
            'reason' => $lastError ?? 'unknown',
        ]);

        return $fallback;
    }

    /**
     * Build the composition prompt with the message context.
     */
    private function buildPrompt(string $message, int $roundCount, string $projectName): string
    {
        $truncated = strlen($message) > 2000 ? mb_substr($message, 0, 2000).' [...truncated]' : $message;

        $context = '';
        if ($projectName !== '') {
            $context .= "Project: {$projectName}. ";
        }
        $context .= "Rounds of tool use: {$roundCount}. ";

        if ($roundCount <= 1) {
            $context .= 'Quick interaction.';
        } elseif ($roundCount <= 5) {
            $context .= 'Moderate task.';
        } else {
            $context .= 'Complex multi-step task.';
        }

        return <<<PROMPT
{$context}

The agent's final message:
"""
{$truncated}
"""

Compose a Python script that sonifies this outcome.

PROMPT;
    }

    /**
     * Call the LLM to generate the composition script.
     */
    private function callLlm(string $userPrompt): string
    {
        $systemPrompt = $this->buildSystemPrompt();

        if (! function_exists('proc_open')) {
            return $this->callLlmDirect($systemPrompt, $userPrompt);
        }

        return $this->callLlmWithTimeout($systemPrompt, $userPrompt);
    }

    private function buildSystemPrompt(): string
    {
        return str_replace(
            ['SOUNDFONT_PLACEHOLDER', 'INSTRUMENT_PLACEHOLDER'],
            [$this->soundfont, (string) $this->instrument],
            self::COMPOSITION_SYSTEM_PROMPT,
        );
    }

    private function callLlmDirect(string $systemPrompt, string $userPrompt): string
    {
        $response = $this->llm->chat([
            new SystemMessage($systemPrompt),
            new UserMessage($userPrompt),
        ]);

        return $this->stripCodeFences(trim($response->text));
    }

    private function callLlmWithTimeout(string $systemPrompt, string $userPrompt): string
    {
        $workerScript = dirname(__DIR__, 2).'/src/Audio/compose_llm_worker.php';
        if (! file_exists($workerScript)) {
            throw new \RuntimeException("Completion sound LLM worker not found: {$workerScript}");
        }

        $payload = base64_encode(json_encode([
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, $workerScript, $payload],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Completion sound LLM worker failed to start');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = 1;
        $start = microtime(true);

        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);

                if (! $status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if ((microtime(true) - $start) >= $this->llmTimeoutSeconds) {
                    proc_terminate($process);
                    usleep(200_000);
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        proc_terminate($process, 9);
                    }

                    throw new \RuntimeException("Completion sound LLM timed out after {$this->llmTimeoutSeconds}s");
                }

                usleep(100_000);
            }
        } finally {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            try {
                fclose($pipes[1]);
            } catch (\Throwable) {
            }
            try {
                fclose($pipes[2]);
            } catch (\Throwable) {
            }
            proc_close($process);
        }

        if ($exitCode !== 0) {
            $message = trim($stderr);
            throw new \RuntimeException($message !== '' ? $message : 'Completion sound LLM worker failed');
        }

        $text = trim($stdout);
        if ($text === '') {
            throw new \RuntimeException('Completion sound LLM worker returned empty output');
        }

        return $this->stripCodeFences($text);
    }

    private function stripCodeFences(string $text): string
    {
        if (preg_match('/^```(?:python)?\s*\n(.+)```$/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        return $text;
    }

    private function buildFallbackScript(string $message, int $roundCount, string $projectName): string
    {
        $outcome = $this->classifyOutcome($message);
        $tempo = match ($outcome) {
            'success', 'tests_passed' => 124,
            'cleanup' => 92,
            'question' => 78,
            'failure', 'tests_failed' => 68,
            'partial' => 88,
            default => 96,
        };

        $melody = match ($outcome) {
            'success' => [[74, 0.0, 0.50, 112], [78, 0.5, 0.50, 108], [81, 1.0, 0.75, 112], [86, 2.0, 1.00, 118]],
            'tests_passed' => [[74, 0.0, 0.40, 110], [76, 0.5, 0.40, 106], [79, 1.0, 0.40, 112], [83, 1.5, 0.75, 116], [86, 2.5, 0.75, 118]],
            'failure' => [[74, 0.0, 0.60, 96], [72, 0.7, 0.50, 88], [69, 1.3, 0.60, 84], [65, 2.1, 1.10, 92]],
            'tests_failed' => [[76, 0.0, 0.35, 98], [74, 0.4, 0.35, 92], [71, 0.8, 0.35, 88], [67, 1.2, 0.35, 84], [62, 1.8, 1.10, 94]],
            'question' => [[74, 0.0, 0.50, 88], [79, 0.7, 0.60, 82], [81, 1.5, 0.75, 78], [79, 2.5, 1.20, 76]],
            'cleanup' => [[71, 0.0, 0.50, 86], [74, 0.5, 0.50, 82], [78, 1.0, 0.50, 84], [81, 1.5, 0.60, 86], [83, 2.2, 1.00, 88]],
            'partial' => [[72, 0.0, 0.45, 92], [76, 0.5, 0.45, 90], [74, 1.0, 0.45, 86], [79, 1.5, 0.60, 90], [77, 2.3, 0.80, 84]],
            default => [[72, 0.0, 0.50, 96], [76, 0.6, 0.50, 94], [79, 1.2, 0.60, 98], [83, 2.0, 0.90, 102]],
        };

        $bass = match ($outcome) {
            'success', 'tests_passed' => [[50, 0.0, 1.0, 72], [57, 1.0, 1.0, 68], [62, 2.0, 1.5, 74]],
            'failure', 'tests_failed' => [[50, 0.0, 1.0, 70], [48, 1.0, 1.0, 66], [45, 2.0, 1.5, 70]],
            'question' => [[50, 0.0, 1.0, 62], [57, 1.1, 1.0, 60], [55, 2.2, 1.2, 58]],
            'cleanup' => [[47, 0.0, 0.8, 64], [54, 0.8, 0.8, 62], [59, 1.6, 0.8, 64], [62, 2.4, 1.0, 66]],
            'partial' => [[48, 0.0, 0.9, 66], [55, 1.0, 0.9, 64], [53, 2.0, 1.2, 62]],
            default => [[48, 0.0, 1.0, 66], [55, 1.0, 1.0, 64], [60, 2.0, 1.2, 68]],
        };

        if ($roundCount > 5) {
            $melody[] = [88, 3.2, 0.60, 104];
            $bass[] = [64, 3.0, 1.0, 68];
        }

        if (str_contains(mb_strtolower($projectName), 'kosmo')) {
            $melody[] = [81, 4.0, 0.60, 96];
        }

        $melodyCode = $this->renderEventsForPython($melody);
        $bassCode = $this->renderEventsForPython($bass);
        $soundfont = addslashes($this->soundfont);
        $instrument = $this->instrument;

        return <<<PY
from midiutil import MIDIFile
import os
import tempfile
import subprocess

sf2_path = os.path.expanduser("{$soundfont}")
midi = MIDIFile(2, adjust_origin=False)
tempo = {$tempo}
instrument = {$instrument}
midi.addTempo(0, 0, tempo)
midi.addTempo(1, 0, tempo)
midi.addProgramChange(0, 0, 0, instrument)
midi.addProgramChange(1, 1, 0, 32)

melody = [
{$melodyCode}
]
bass = [
{$bassCode}
]

for pitch, beat, duration, velocity in melody:
    midi.addNote(0, 0, pitch, beat, duration, velocity)

for pitch, beat, duration, velocity in bass:
    midi.addNote(1, 1, pitch, beat, duration, velocity)

fd, midi_path = tempfile.mkstemp(suffix=".mid")
os.close(fd)
with open(midi_path, "wb") as handle:
    midi.writeFile(handle)

proc = subprocess.Popen(['fluidsynth', '-q', '-a', 'coreaudio', '-n', '-i', '-l', sf2_path, midi_path])
try:
    proc.wait(timeout=15)
finally:
    if proc.poll() is None:
        proc.kill()
    try:
        os.unlink(midi_path)
    except FileNotFoundError:
        pass
PY;
    }

    private function classifyOutcome(string $message): string
    {
        $message = mb_strtolower($message);

        if (preg_match('/\b(test(?:s)? (?:pass|passed|passing)|all tests passed)\b/u', $message)) {
            return 'tests_passed';
        }

        if (preg_match('/\b(test(?:s)? (?:fail|failed|failing)|assertion failed)\b/u', $message)) {
            return 'tests_failed';
        }

        if (preg_match('/\b(confirm|approval|approve|permission|would you like|shall i|let me know)\b/u', $message)) {
            return 'question';
        }

        if (preg_match('/\b(error|failed|failure|exception|cannot|unable|denied|timeout|timed out)\b/u', $message)) {
            return 'failure';
        }

        if (preg_match('/\b(refactor|cleanup|clean up|document|docblock|comment)\b/u', $message)) {
            return 'cleanup';
        }

        if (preg_match('/\b(partial|progress|investigat|explor|scan|search|read)\b/u', $message)) {
            return 'partial';
        }

        if (preg_match('/\b(success|successful|successfully|fixed|implemented|completed|done)\b/u', $message)) {
            return 'success';
        }

        return 'neutral';
    }

    /**
     * @param  list<array{0:int,1:float,2:float,3:int}>  $events
     */
    private function renderEventsForPython(array $events): string
    {
        $lines = array_map(
            static fn (array $event): string => sprintf('    (%d, %.2f, %.2f, %d),', $event[0], $event[1], $event[2], $event[3]),
            $events,
        );

        return implode("\n", $lines);
    }

    /**
     * Validate that the script is syntactically correct Python, contains expected
     * midiutil usage, and has no forbidden imports.
     */
    private function validateScript(string $script): bool
    {
        // Must use midiutil
        if (! str_contains($script, 'midiutil') && ! str_contains($script, 'MIDIFile')) {
            $this->log->warning('Completion sound: script rejected — no midiutil usage');

            return false;
        }

        // Must contain addNote (actual music)
        if (! str_contains($script, 'addNote')) {
            $this->log->warning('Completion sound: script rejected — no addNote calls');

            return false;
        }

        // Must play via fluidsynth
        if (! str_contains($script, 'fluidsynth')) {
            return false;
        }

        // Check Python syntax by running `python3 -c "compile(...)"`
        $tmpCheck = sys_get_temp_dir().'/kosmokrator_syntax_check_'.uniqid().'.py';
        file_put_contents($tmpCheck, $script);
        exec('python3 -m py_compile '.escapeshellarg($tmpCheck).' 2>&1', $compileOutput, $compileExit);
        @unlink($tmpCheck);

        if ($compileExit !== 0) {
            $this->log->warning('Completion sound: script rejected — Python syntax error', [
                'output' => implode("\n", $compileOutput),
            ]);

            return false;
        }

        // Forbidden patterns — network, filesystem destruction, code injection
        $forbidden = [
            'socket', 'urllib', 'requests', 'http\.client',
            'eval\s*\(', 'exec\s*\(', '__import__',
            'shell\s*=\s*True',
            'os\.system\s*\(',
            'shutil\.rmtree', 'send2trash', 'rmtree',
        ];

        foreach ($forbidden as $pattern) {
            if (preg_match('/'.$pattern.'/i', $script)) {
                $this->log->warning('Completion sound: script rejected — forbidden pattern', [
                    'pattern' => $pattern,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Spawn the Python script as a fire-and-forget background process.
     */
    private function spawnPlayback(string $script): void
    {
        $tmpPath = sys_get_temp_dir().'/kosmokrator_sound_'.uniqid().'.py';
        file_put_contents($tmpPath, $script);

        $command = sprintf(
            'python3 %s > /dev/null 2>&1 &',
            escapeshellarg($tmpPath),
        );

        $this->log->info('Completion sound: spawning playback process', [
            'script_path' => $tmpPath,
            'instrument' => $this->getInstrumentName(),
        ]);

        exec($command);

        // Schedule cleanup after a generous timeout
        $this->scheduleCleanup($tmpPath);
    }

    /**
     * Schedule temp file cleanup via a delayed background command.
     */
    private function scheduleCleanup(string $path): void
    {
        $command = sprintf(
            '(sleep 20 && rm -f %s) > /dev/null 2>&1 &',
            escapeshellarg($path),
        );
        exec($command);
    }

    /**
     * Get the instrument name for logging/display.
     */
    public function getInstrumentName(): string
    {
        $names = [
            0 => 'Piano',
            11 => 'Vibraphone',
            24 => 'Guitar',
            41 => 'Violin',
            46 => 'Harp',
            56 => 'Trumpet',
            73 => 'Flute',
            98 => 'Crystal Pad',
            105 => 'Banjo',
            112 => 'Fiddle',
        ];

        return $names[$this->instrument] ?? "Program {$this->instrument}";
    }
}
