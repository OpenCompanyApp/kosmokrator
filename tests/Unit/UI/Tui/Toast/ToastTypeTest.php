<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\UI\Tui\Toast;

use Kosmokrator\UI\Tui\Toast\ToastType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToastTypeTest extends TestCase
{
    public function test_icons(): void
    {
        $this->assertSame('✓', ToastType::Success->icon());
        $this->assertSame('⚠', ToastType::Warning->icon());
        $this->assertSame('✕', ToastType::Error->icon());
        $this->assertSame('ℹ', ToastType::Info->icon());
    }

    public function test_durations(): void
    {
        $this->assertSame(2000, ToastType::Success->defaultDuration());
        $this->assertSame(3000, ToastType::Warning->defaultDuration());
        $this->assertSame(4000, ToastType::Error->defaultDuration());
        $this->assertSame(2000, ToastType::Info->defaultDuration());
    }

    public function test_foreground_color(): void
    {
        foreach (ToastType::cases() as $type) {
            $color = $type->foregroundColor();
            $this->assertStringStartsWith("\033[38;2;", $color, "{$type->name} foreground should be 24-bit color");
            $this->assertStringEndsWith('m', $color, "{$type->name} foreground should end with 'm'");
        }
    }

    public function test_border_color(): void
    {
        foreach (ToastType::cases() as $type) {
            $color = $type->borderColor();
            $this->assertStringStartsWith("\033[38;2;", $color, "{$type->name} border should be 24-bit color");
        }
    }

    public function test_background_color(): void
    {
        foreach (ToastType::cases() as $type) {
            $color = $type->backgroundColor();
            $this->assertStringStartsWith("\033[48;2;", $color, "{$type->name} background should be 24-bit bg color");
        }
    }

    public function test_border_dim_color(): void
    {
        foreach (ToastType::cases() as $type) {
            $color = $type->borderDimColor();
            $this->assertStringStartsWith("\033[38;2;", $color, "{$type->name} dim border should be 24-bit color");
        }
    }

    #[DataProvider('backingValueProvider')]
    public function test_backing_values(ToastType $type, string $expected): void
    {
        $this->assertSame($expected, $type->value);
    }

    public static function backingValueProvider(): array
    {
        return [
            'success' => [ToastType::Success, 'success'],
            'warning' => [ToastType::Warning, 'warning'],
            'error' => [ToastType::Error, 'error'],
            'info' => [ToastType::Info, 'info'],
        ];
    }
}
