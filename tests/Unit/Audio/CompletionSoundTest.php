<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Audio;

use Kosmokrator\Audio\CompletionSound;
use Kosmokrator\LLM\LlmClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CompletionSoundTest extends TestCase
{
    private LlmClientInterface&MockObject $llm;
    private LoggerInterface&MockObject $log;

    protected function setUp(): void
    {
        $this->llm = $this->createMock(LlmClientInterface::class);
        $this->log = $this->createMock(LoggerInterface::class);
    }

    private function createSound(
        string $sessionId = 'test-session',
        ?bool $enabled = null,
        ?string $soundfont = null,
        int $maxDuration = 8,
        int $maxRetries = 1,
        int $llmTimeoutSeconds = 60,
    ): CompletionSound {
        return new CompletionSound(
            $this->llm,
            $this->log,
            $sessionId,
            $enabled,
            $soundfont,
            $maxDuration,
            $maxRetries,
            $llmTimeoutSeconds,
        );
    }

    // ── Constructor ──────────────────────────────────────────────────────

    public function test_constructor_with_defaults(): void
    {
        $sound = $this->createSound();
        $this->assertFalse($sound->isEnabled());
    }

    public function test_constructor_with_custom_values(): void
    {
        $sound = $this->createSound(
            sessionId: 'custom',
            enabled: true,
            soundfont: '/tmp/custom.sf2',
            maxDuration: 12,
            maxRetries: 3,
        );
        $this->assertTrue($sound->isEnabled());
    }

    // ── isEnabled() ──────────────────────────────────────────────────────

    public function test_is_enabled_returns_false_by_default(): void
    {
        $sound = $this->createSound();
        $this->assertFalse($sound->isEnabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        $sound = $this->createSound(enabled: true);
        $this->assertTrue($sound->isEnabled());
    }

    // ── getInstrumentName() ──────────────────────────────────────────────

    public function test_get_instrument_name_returns_valid_name(): void
    {
        $sound = $this->createSound();
        $name = $sound->getInstrumentName();

        // Must be one of the known names or a "Program N" fallback
        $validNames = [
            'Piano', 'Vibraphone', 'Guitar', 'Violin', 'Harp',
            'Trumpet', 'Flute', 'Crystal Pad', 'Banjo', 'Fiddle',
        ];

        // The instrument is deterministic for 'test-session', just verify it's a string
        $this->assertIsString($name);
        $this->assertContains($name, $validNames);
    }

    public function test_get_instrument_name_with_unknown_instrument(): void
    {
        // Use reflection to force an unknown instrument value
        $sound = $this->createSound();
        $ref = new \ReflectionClass($sound);
        $prop = $ref->getProperty('instrument');
        $prop->setValue($sound, 999);

        $this->assertSame('Program 999', $sound->getInstrumentName());
    }

    // ── Deterministic instrument assignment ──────────────────────────────

    public function test_deterministic_instrument_for_same_session_id(): void
    {
        $sound1 = $this->createSound(sessionId: 'abc123');
        $sound2 = $this->createSound(sessionId: 'abc123');

        $ref = new \ReflectionClass($sound1);
        $prop = $ref->getProperty('instrument');

        $this->assertSame($prop->getValue($sound1), $prop->getValue($sound2));
        $this->assertSame($sound1->getInstrumentName(), $sound2->getInstrumentName());
    }

    public function test_different_session_ids_may_get_different_instruments(): void
    {
        // Pick IDs far enough apart that crc32 differs
        $soundA = $this->createSound(sessionId: 'alpha');
        $soundB = $this->createSound(sessionId: 'beta');

        // They *may* differ; we just confirm both produce valid instruments
        $instruments = [
            $soundA->getInstrumentName(),
            $soundB->getInstrumentName(),
        ];

        foreach ($instruments as $name) {
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    // ── INSTRUMENTS constant ─────────────────────────────────────────────

    public function test_instruments_constant_has_ten_entries(): void
    {
        $ref = new \ReflectionClass(CompletionSound::class);
        $constants = $ref->getConstants();

        $this->assertArrayHasKey('INSTRUMENTS', $constants);
        $this->assertCount(10, $constants['INSTRUMENTS']);
    }

    // ── play() behaviour ─────────────────────────────────────────────────

    public function test_play_does_nothing_when_disabled(): void
    {
        $this->log->expects($this->never())->method('info');
        $this->log->expects($this->never())->method('warning');
        $this->log->expects($this->never())->method('error');

        $sound = $this->createSound(enabled: false);
        $sound->play('Some message');
    }

    public function test_play_logs_warning_when_soundfont_not_found(): void
    {
        $this->log->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('soundfont not found'),
                $this->callback(fn (array $ctx) => isset($ctx['path'])),
            );

        $sound = $this->createSound(
            enabled: true,
            soundfont: '/nonexistent/path/soundfont.sf2',
        );
        $sound->play('Some message');
    }

    // ── validateScript() via reflection ──────────────────────────────────

    private function invokeValidateScript(CompletionSound $sound, string $script): bool
    {
        $ref = new \ReflectionClass($sound);
        $method = $ref->getMethod('validateScript');

        return $method->invoke($sound, $script);
    }

    public function test_validate_script_rejects_without_midiutil(): void
    {
        // Has addNote and fluidsynth but no midiutil/MIDIFile
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
import subprocess
midi.addNote(0, 0, 60, 0, 1, 100)
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_without_addNote(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
from midiutil.MIDIFile import MIDIFile
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_without_fluidsynth(): void
    {
        // Build a minimal valid Python script that has midiutil + addNote but no fluidsynth
        $script = <<<'PY'
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
with open("/tmp/out.mid", "wb") as f:
    midi.writeFile(f)
PY;
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, $script);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_socket(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
import socket
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_urllib(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
import urllib.request
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_eval(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
eval("print('bad')")
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_exec(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
exec("import os")
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_requests(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
import requests
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_os_system(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
os.system("rm -rf /")
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    public function test_validate_script_rejects_forbidden_rmtree(): void
    {
        $sound = $this->createSound();
        $result = $this->invokeValidateScript($sound, <<<PY
import shutil
from midiutil.MIDIFile import MIDIFile
midi = MIDIFile(1)
midi.addNote(0, 0, 60, 0, 1, 100)
shutil.rmtree("/tmp")
subprocess.run(['fluidsynth', '-q', 'a.sf2', 'b.mid'])
PY);

        $this->assertFalse($result);
    }

    // ── buildPrompt() via reflection ─────────────────────────────────────

    private function invokeBuildPrompt(CompletionSound $sound, string $message, int $roundCount = 1, string $projectName = ''): string
    {
        $ref = new \ReflectionClass($sound);
        $method = $ref->getMethod('buildPrompt');

        return $method->invoke($sound, $message, $roundCount, $projectName);
    }

    private function invokeBuildFallbackScript(CompletionSound $sound, string $message, int $roundCount = 1, string $projectName = ''): string
    {
        $ref = new \ReflectionClass($sound);
        $method = $ref->getMethod('buildFallbackScript');

        return $method->invoke($sound, $message, $roundCount, $projectName);
    }

    public function test_build_prompt_contains_round_count_context(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'Hello', 3, 'myproject');

        $this->assertStringContainsString('Project: myproject', $prompt);
        $this->assertStringContainsString('Rounds of tool use: 3', $prompt);
        $this->assertStringContainsString('Moderate task', $prompt);
        $this->assertStringContainsString('Hello', $prompt);
    }

    public function test_build_prompt_classifies_quick_interaction(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'Hi', 1);

        $this->assertStringContainsString('Quick interaction', $prompt);
    }

    public function test_build_prompt_classifies_complex_task(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'Big task', 10);

        $this->assertStringContainsString('Complex multi-step task', $prompt);
    }

    public function test_build_prompt_truncates_long_messages(): void
    {
        $longMessage = str_repeat('x', 3000);

        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, $longMessage);

        $this->assertStringContainsString('[...truncated]', $prompt);
        // The original 3000-char message should NOT appear in full
        $this->assertLessThan(3000, strlen($prompt));
    }

    public function test_build_prompt_does_not_truncate_short_messages(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'Short message');

        $this->assertStringNotContainsString('[...truncated]', $prompt);
        $this->assertStringContainsString('Short message', $prompt);
    }

    public function test_build_prompt_omits_project_when_empty(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'msg', 1, '');

        $this->assertStringNotContainsString('Project:', $prompt);
    }

    public function test_build_prompt_includes_composition_instruction(): void
    {
        $sound = $this->createSound();
        $prompt = $this->invokeBuildPrompt($sound, 'msg', 1);

        $this->assertStringContainsString('Compose a Python script', $prompt);
    }

    public function test_build_fallback_script_is_valid_for_success_case(): void
    {
        $sound = $this->createSound(soundfont: '/tmp/test.sf2');
        $script = $this->invokeBuildFallbackScript(
            $sound,
            'Implemented the fix successfully and all tests passed.',
            3,
            'kosmokrator',
        );

        $this->assertTrue($this->invokeValidateScript($sound, $script));
        $this->assertStringContainsString('fluidsynth', $script);
        $this->assertStringContainsString('/tmp/test.sf2', $script);
    }

    public function test_build_fallback_script_is_valid_for_failure_case(): void
    {
        $sound = $this->createSound(soundfont: '/tmp/test.sf2');
        $script = $this->invokeBuildFallbackScript(
            $sound,
            'The tests failed with an exception and the task could not be completed.',
            2,
            'kosmokrator',
        );

        $this->assertTrue($this->invokeValidateScript($sound, $script));
        $this->assertStringContainsString('midi.addNote', $script);
    }
}
