<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Toast;

use Kosmokrator\UI\TerminalNotification;
use Kosmokrator\UI\Tui\Toast\ToastItem;
use Kosmokrator\UI\Tui\Toast\ToastManager;
use Kosmokrator\UI\Tui\Toast\ToastPhase;
use Kosmokrator\UI\Tui\Toast\ToastType;
use PHPUnit\Framework\TestCase;

final class ToastManagerTest extends TestCase
{
    private bool $desktopNotificationFired = false;

    protected function setUp(): void
    {
        ToastManager::reset();

        // Reset ID counter for predictable tests
        $ref = new \ReflectionProperty(ToastItem::class, 'idCounter');
        $ref->setValue(null, 0);

        $this->desktopNotificationFired = false;
        TerminalNotification::setWriter(function (string $data): void {
            $this->desktopNotificationFired = true;
        });
    }

    protected function tearDown(): void
    {
        ToastManager::reset();
        TerminalNotification::setWriter(null);
    }

    public function test_add_toast(): void
    {
        $manager = ToastManager::getInstance();
        $toast = $manager->addToast(new ToastItem('Hello', ToastType::Info));

        $stack = $manager->toasts->get();
        $this->assertCount(1, $stack);
        $this->assertSame($toast->id, $stack[0]->id);
    }

    public function test_static_show(): void
    {
        $toast = ToastManager::show('Test', ToastType::Success);
        $stack = ToastManager::getInstance()->toasts->get();
        $this->assertCount(1, $stack);
        $this->assertSame($toast->id, $stack[0]->id);
    }

    public function test_static_convenience_methods(): void
    {
        ToastManager::success('ok');
        ToastManager::warning('warn');
        ToastManager::error('err');
        ToastManager::info('info');

        $stack = ToastManager::getInstance()->toasts->get();
        $this->assertCount(4, $stack);
        $this->assertSame(ToastType::Info, $stack[0]->type);     // newest first (info)
        $this->assertSame(ToastType::Error, $stack[1]->type);    // error
        $this->assertSame(ToastType::Warning, $stack[2]->type);  // warning
        $this->assertSame(ToastType::Success, $stack[3]->type);  // oldest (success)
    }

    public function test_max_visible_dismisses_oldest(): void
    {
        $manager = ToastManager::getInstance();

        // Add 5 toasts
        $toasts = [];
        for ($i = 0; $i < 5; $i++) {
            $toasts[] = $manager->addToast(new ToastItem("Toast {$i}", ToastType::Info));
        }

        // Stack is newest-first: [Toast 4, Toast 3, Toast 2, Toast 1, Toast 0]
        // Toast 0 ($toasts[0]) is the oldest (last in the array)
        $this->assertCount(5, $manager->toasts->get());

        // Add 6th toast — should dismiss the oldest ($toasts[0])
        $sixth = $manager->addToast(new ToastItem('Toast 5', ToastType::Info));

        $stack = $manager->toasts->get();
        // The oldest toast ($toasts[0]) should be exiting
        $this->assertSame(ToastPhase::Exiting, $toasts[0]->phase->get());
        // The 6th toast should be at the top
        $this->assertSame($sixth->id, $stack[0]->id);
    }

    public function test_dismiss_toast(): void
    {
        $manager = ToastManager::getInstance();
        $toast = $manager->addToast(new ToastItem('Test', ToastType::Info));

        $manager->dismissToast($toast);
        $this->assertSame(ToastPhase::Exiting, $toast->phase->get());
    }

    public function test_dismiss_toast_already_exiting(): void
    {
        $manager = ToastManager::getInstance();
        $toast = $manager->addToast(new ToastItem('Test', ToastType::Info));

        $manager->dismissToast($toast);
        $phaseAfterFirst = $toast->phase->get();

        // Calling again should be a no-op
        $manager->dismissToast($toast);
        $this->assertSame($phaseAfterFirst, $toast->phase->get());
    }

    public function test_dismiss_all(): void
    {
        $manager = ToastManager::getInstance();
        $manager->addToast(new ToastItem('A', ToastType::Info));
        $manager->addToast(new ToastItem('B', ToastType::Info));
        $manager->addToast(new ToastItem('C', ToastType::Info));

        $manager->dismissAllToasts();

        foreach ($manager->toasts->get() as $toast) {
            $this->assertSame(ToastPhase::Exiting, $toast->phase->get());
        }
    }

    public function test_static_dismiss_all(): void
    {
        ToastManager::info('A');
        ToastManager::info('B');
        ToastManager::dismissAll();

        foreach (ToastManager::getInstance()->toasts->get() as $toast) {
            $this->assertSame(ToastPhase::Exiting, $toast->phase->get());
        }
    }

    public function test_remove_toast(): void
    {
        $manager = ToastManager::getInstance();
        $toast1 = $manager->addToast(new ToastItem('A', ToastType::Info));
        $toast2 = $manager->addToast(new ToastItem('B', ToastType::Info));

        $this->assertCount(2, $manager->toasts->get());

        $manager->removeToast($toast2);
        $stack = $manager->toasts->get();
        $this->assertCount(1, $stack);
        $this->assertSame($toast1->id, $stack[0]->id);
    }

    public function test_entrance_animation_sets_initial_state(): void
    {
        $manager = ToastManager::getInstance();
        $toast = $manager->addToast(new ToastItem('Test', ToastType::Info));

        // Entrance animation sets these initial values
        $this->assertSame(0.0, $toast->opacity->get());
        $this->assertSame(30, $toast->slideOffset->get());
        $this->assertSame(ToastPhase::Entering, $toast->phase->get());
    }

    public function test_desktop_bridge_on_error(): void
    {
        ToastManager::error('Something broke');
        $this->assertTrue($this->desktopNotificationFired, 'Error toast should trigger desktop notification');
    }

    public function test_no_desktop_bridge_on_success(): void
    {
        ToastManager::success('All good');
        $this->assertFalse($this->desktopNotificationFired, 'Success toast should not trigger desktop notification');
    }

    public function test_desktop_bridge_can_be_disabled(): void
    {
        $manager = ToastManager::getInstance();
        $manager->setDesktopNotifyOnError(false);

        ToastManager::error('Something broke');
        $this->assertFalse($this->desktopNotificationFired, 'Desktop notification should not fire when disabled');
    }

    public function test_reset_clears_instance(): void
    {
        ToastManager::info('A');
        $first = ToastManager::getInstance();

        ToastManager::reset();
        $second = ToastManager::getInstance();

        $this->assertNotSame($first, $second);
        $this->assertCount(0, $second->toasts->get());
    }

    public function test_get_toast_at_returns_null_for_empty_stack(): void
    {
        $manager = ToastManager::getInstance();
        $result = $manager->getToastAt(10, 70, 24, 80, 1);
        $this->assertNull($result);
    }

    public function test_get_toast_at_returns_null_for_miss(): void
    {
        $manager = ToastManager::getInstance();
        // Add a toast but it's entering (opacity 0), hit-test should work by position
        $manager->addToast(new ToastItem('Test', ToastType::Info));

        // Click in the top-left corner — should miss
        $result = $manager->getToastAt(1, 1, 24, 80, 1);
        $this->assertNull($result);
    }

    public function test_get_toast_at_returns_toast_on_hit(): void
    {
        $manager = ToastManager::getInstance();
        $toast = $manager->addToast(new ToastItem('Test', ToastType::Info));

        // Make the toast visible so it's not skipped
        $toast->phase->set(ToastPhase::Visible);

        // 24-row viewport, 80 cols, 1-row status bar
        // Toast is at bottom-right: marginBottom = 2, baseRow = 22
        // toastMaxWidth = min(50, 80-2-4) = 50
        // Single-line message → visibleLines = 1, toastTop = 22
        // toastLeft = 80 - 2 - 50 = 28, toastRight = 80 - 2 = 78
        $result = $manager->getToastAt(row: 22, col: 50, viewportRows: 24, viewportCols: 80, statusBarRows: 1);

        $this->assertNotNull($result);
        $this->assertSame($toast->id, $result->id);
    }
}
