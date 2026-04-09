<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Primitive;

use Athanor\Signal;
use Kosmokrator\UI\Tui\Primitive\Collection\When;
use Kosmokrator\UI\Tui\Primitive\Collection\WhenBinding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class WhenTest extends TestCase
{
    public function test_show_returns_binding(): void
    {
        $condition = new Signal(false);
        $binding = When::show($condition, fn () => new TextWidget('child'));

        $this->assertInstanceOf(WhenBinding::class, $binding);
    }

    public function test_attach_creates_child_when_condition_true(): void
    {
        $condition = new Signal(false);
        $binding = When::show($condition, fn () => new TextWidget('child'));
        $parent = new ContainerWidget;

        $binding->attach($parent);

        // Initially false, no child
        $this->assertNull($binding->getChild());
        $this->assertCount(0, $parent->all());

        // Set condition to true — triggers effect
        $condition->set(true);

        // The effect runs synchronously in Athanor when the signal changes
        $this->assertNotNull($binding->getChild());
    }

    public function test_detach_removes_child(): void
    {
        $condition = new Signal(false);
        $binding = When::show($condition, fn () => new TextWidget('child'));
        $parent = new ContainerWidget;

        $binding->attach($parent);
        $condition->set(true);

        $binding->detach($parent);

        $this->assertNull($binding->getChild());
    }

    public function test_initial_child_is_null(): void
    {
        $condition = new Signal(false);
        $binding = When::show($condition, fn () => new TextWidget('child'));

        $this->assertNull($binding->getChild());
    }
}
