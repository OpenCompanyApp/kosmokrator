<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Widget;

use Kosmokrator\UI\Tui\Widget\PlanApprovalWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * @psalm-suppress PossiblyNullFunctionCall
 */
final class PlanApprovalWidgetTest extends TestCase
{
    public function test_constructor_sets_default_permission_from_guardian(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $this->assertSame('guardian', $widget->getPermissionId());
    }

    public function test_constructor_sets_permission_from_argus(): void
    {
        $widget = new PlanApprovalWidget('argus');

        $this->assertSame('argus', $widget->getPermissionId());
    }

    public function test_constructor_sets_permission_from_prometheus(): void
    {
        $widget = new PlanApprovalWidget('prometheus');

        $this->assertSame('prometheus', $widget->getPermissionId());
    }

    public function test_constructor_defaults_to_first_permission_on_unknown(): void
    {
        $widget = new PlanApprovalWidget('unknown_mode');

        $this->assertSame('guardian', $widget->getPermissionId());
    }

    public function test_getContextId_defaults_to_keep(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $this->assertSame('keep', $widget->getContextId());
    }

    public function test_render_produces_bordered_output(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $lines = $widget->render(new RenderContext(80, 24));

        $this->assertNotEmpty($lines);
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Implement', $content);
        $this->assertStringContainsString('Dismiss', $content);
        $this->assertStringContainsString('Plan complete', $content);
    }

    public function test_render_contains_permission_label(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('Guardian', $content);
    }

    public function test_render_contains_context_label(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $lines = $widget->render(new RenderContext(80, 24));
        $content = implode("\n", $lines);

        $this->assertStringContainsString('keep context', $content);
    }

    public function test_onConfirm_registers_callback(): void
    {
        $widget = new PlanApprovalWidget('guardian');
        $called = false;

        $widget->onConfirm(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput("\r"); // Enter

        $this->assertTrue($called, 'Expected confirm callback to be invoked');
    }

    public function test_onDismiss_registers_callback(): void
    {
        $widget = new PlanApprovalWidget('guardian');
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        // Navigate to row 3 (Dismiss) then Enter
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B"); // down → row 3
        $widget->handleInput("\r"); // enter

        $this->assertTrue($called, 'Expected dismiss callback to be invoked on row 3 + Enter');
    }

    public function test_handleInput_escape_triggers_dismiss(): void
    {
        $widget = new PlanApprovalWidget('guardian');
        $called = false;

        $widget->onDismiss(function () use (&$called): void {
            $called = true;
        });

        $widget->handleInput("\x1b"); // Escape

        $this->assertTrue($called, 'Expected dismiss callback on Escape');
    }

    public function test_handleInput_navigates_rows_with_up_down(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $widget->handleInput("\x1b[B"); // down → row 1 (permission toggle)
        $widget->handleInput("\x1b[C"); // right → cycle permission
        $this->assertSame('argus', $widget->getPermissionId());

        $widget->handleInput("\x1b[B"); // down → row 2 (context toggle)
        $widget->handleInput("\x1b[C"); // right → cycle context
        $this->assertSame('compact', $widget->getContextId());
    }

    public function test_handleInput_left_cycles_permission_backwards(): void
    {
        $widget = new PlanApprovalWidget('prometheus');

        $widget->handleInput("\x1b[B"); // down → row 1
        $widget->handleInput("\x1b[D"); // left → prometheus → argus

        $this->assertSame('argus', $widget->getPermissionId());
    }

    public function test_handleInput_right_cycles_permission_forward(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $widget->handleInput("\x1b[B"); // down → row 1
        $widget->handleInput("\x1b[C"); // right → guardian → argus

        $this->assertSame('argus', $widget->getPermissionId());
    }

    public function test_handleInput_cycles_context(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $widget->handleInput("\x1b[B"); // down → row 1
        $widget->handleInput("\x1b[B"); // down → row 2 (context)
        $widget->handleInput("\x1b[C"); // right → keep → compact

        $this->assertSame('compact', $widget->getContextId());

        $widget->handleInput("\x1b[C"); // right → compact → clear
        $this->assertSame('clear', $widget->getContextId());

        $widget->handleInput("\x1b[C"); // right → clear → keep (wraps)
        $this->assertSame('keep', $widget->getContextId());
    }

    public function test_handleInput_up_wraps_from_row_0_to_row_3(): void
    {
        $widget = new PlanApprovalWidget('guardian');
        $dismissCalled = false;

        $widget->onDismiss(function () use (&$dismissCalled): void {
            $dismissCalled = true;
        });

        $widget->handleInput("\x1b[A"); // up → wraps to row 3
        $widget->handleInput("\r"); // enter → dismiss (row 3)

        $this->assertTrue($dismissCalled, 'Expected dismiss when on row 3 (wrapping from 0)');
    }

    public function test_handleInput_down_wraps_from_row_3_to_row_0(): void
    {
        $widget = new PlanApprovalWidget('guardian');
        $confirmCalled = false;

        $widget->onConfirm(function () use (&$confirmCalled): void {
            $confirmCalled = true;
        });

        $widget->handleInput("\x1b[B"); // down → row 1
        $widget->handleInput("\x1b[B"); // down → row 2
        $widget->handleInput("\x1b[B"); // down → row 3
        $widget->handleInput("\x1b[B"); // down → wraps to row 0
        $widget->handleInput("\r"); // enter → confirm (row 0)

        $this->assertTrue($confirmCalled, 'Expected confirm after wrapping from row 3 to 0');
    }

    public function test_handleInput_left_right_does_nothing_on_row_0(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $widget->handleInput("\x1b[D"); // left → no effect on row 0
        $this->assertSame('guardian', $widget->getPermissionId());
        $this->assertSame('keep', $widget->getContextId());

        $widget->handleInput("\x1b[C"); // right → no effect on row 0
        $this->assertSame('guardian', $widget->getPermissionId());
        $this->assertSame('keep', $widget->getContextId());
    }

    public function test_onConfirm_returns_static_for_chaining(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $result = $widget->onConfirm(static fn (): bool => true);

        $this->assertSame($widget, $result);
    }

    public function test_onDismiss_returns_static_for_chaining(): void
    {
        $widget = new PlanApprovalWidget('guardian');

        $result = $widget->onDismiss(static fn (): bool => true);

        $this->assertSame($widget, $result);
    }
}
