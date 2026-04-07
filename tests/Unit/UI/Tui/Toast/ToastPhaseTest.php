<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Toast\ToastPhase;
use PHPUnit\Framework\TestCase;

final class ToastPhaseTest extends TestCase
{
    public function testPhasesHaveCorrectValues(): void
    {
        $this->assertSame('entering', ToastPhase::Entering->value);
        $this->assertSame('visible', ToastPhase::Visible->value);
        $this->assertSame('exiting', ToastPhase::Exiting->value);
        $this->assertSame('done', ToastPhase::Done->value);
    }

    public function testAllPhasesExist(): void
    {
        $phases = ToastPhase::cases();
        $this->assertCount(4, $phases);
        $this->assertContains(ToastPhase::Entering, $phases);
        $this->assertContains(ToastPhase::Visible, $phases);
        $this->assertContains(ToastPhase::Exiting, $phases);
        $this->assertContains(ToastPhase::Done, $phases);
    }
}
