<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Toast\ToastItem;
use Kosmokrator\UI\Tui\Toast\ToastPhase;
use Kosmokrator\UI\Tui\Toast\ToastType;
use PHPUnit\Framework\TestCase;

final class ToastItemTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset ID counter for predictable tests
        $ref = new \ReflectionProperty(ToastItem::class, 'idCounter');
        $ref->setAccessible(true);
        $ref->setValue(null, 0);
    }

    public function test_factory_methods(): void
    {
        $success = ToastItem::success('ok');
        $this->assertSame(ToastType::Success, $success->type);
        $this->assertSame('ok', $success->message);

        $warning = ToastItem::warning('careful');
        $this->assertSame(ToastType::Warning, $warning->type);

        $error = ToastItem::error('fail');
        $this->assertSame(ToastType::Error, $error->type);

        $info = ToastItem::info('note');
        $this->assertSame(ToastType::Info, $info->type);
    }

    public function test_initial_phase(): void
    {
        $toast = ToastItem::info('test');
        $this->assertSame(ToastPhase::Entering, $toast->phase->get());
    }

    public function test_initial_opacity_is_zero(): void
    {
        $toast = ToastItem::info('test');
        $this->assertSame(0.0, $toast->opacity->get());
    }

    public function test_initial_slide_offset(): void
    {
        $toast = ToastItem::info('test');
        $this->assertSame(40, $toast->slideOffset->get());
    }

    public function test_default_duration_from_type(): void
    {
        $this->assertSame(2000, ToastItem::success('ok')->durationMs);
        $this->assertSame(3000, ToastItem::warning('careful')->durationMs);
        $this->assertSame(4000, ToastItem::error('fail')->durationMs);
        $this->assertSame(2000, ToastItem::info('note')->durationMs);
    }

    public function test_custom_duration_overrides_default(): void
    {
        $toast = ToastItem::success('ok', 5000);
        $this->assertSame(5000, $toast->durationMs);
    }

    public function test_zero_duration_uses_type_default(): void
    {
        $toast = ToastItem::success('ok', 0);
        $this->assertSame(2000, $toast->durationMs);
    }

    public function test_is_auto_dismiss(): void
    {
        $auto = ToastItem::success('auto');
        $this->assertTrue($auto->isAutoDismiss());
    }

    public function test_dismiss_transitions_to_exiting(): void
    {
        $toast = ToastItem::info('test');
        $toast->dismiss();
        $this->assertSame(ToastPhase::Exiting, $toast->phase->get());
    }

    public function test_dismiss_from_done_is_noop(): void
    {
        $toast = ToastItem::info('test');
        $toast->markDone();
        $this->assertSame(ToastPhase::Done, $toast->phase->get());

        // Calling dismiss on a Done toast should not change phase
        $toast->dismiss();
        $this->assertSame(ToastPhase::Done, $toast->phase->get());
    }

    public function test_mark_done(): void
    {
        $toast = ToastItem::info('test');
        $toast->markDone();
        $this->assertSame(ToastPhase::Done, $toast->phase->get());
        $this->assertSame(0.0, $toast->opacity->get());
    }

    public function test_unique_id_increments(): void
    {
        $a = ToastItem::info('a');
        $b = ToastItem::info('b');
        $this->assertGreaterThan($a->id, $b->id);
    }

    public function test_created_at_is_set(): void
    {
        $before = microtime(true);
        $toast = ToastItem::info('test');
        $after = microtime(true);
        $this->assertGreaterThanOrEqual($before, $toast->createdAt);
        $this->assertLessThanOrEqual($after, $toast->createdAt);
    }

    public function test_custom_created_at(): void
    {
        $time = 1000.0;
        $toast = new ToastItem('test', ToastType::Info, 0, $time);
        $this->assertSame($time, $toast->createdAt);
    }
}
