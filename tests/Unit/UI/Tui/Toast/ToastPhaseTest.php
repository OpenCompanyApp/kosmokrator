<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Toast\ToastPhase;
use PHPUnit\Framework\TestCase;

final class ToastPhaseTest extends TestCase
{
    public function test_phases_have_correct_values(): void
    {
        $this->assertSame('entering', ToastPhase::Entering->value);
        $this->assertSame('visible', ToastPhase::Visible->value);
        $this->assertSame('exiting', ToastPhase::Exiting->value);
        $this->assertSame('done', ToastPhase::Done->value);
    }

    public function test_all_phases_exist(): void
    {
        $phases = ToastPhase::cases();
        $this->assertCount(4, $phases);
        $this->assertContains(ToastPhase::Entering, $phases);
        $this->assertContains(ToastPhase::Visible, $phases);
        $this->assertContains(ToastPhase::Exiting, $phases);
        $this->assertContains(ToastPhase::Done, $phases);
    }
}
