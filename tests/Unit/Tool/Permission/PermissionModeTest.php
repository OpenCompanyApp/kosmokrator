<?php

namespace Kosmokrator\Tests\Unit\Tool\Permission;

use Kosmokrator\Tool\Permission\PermissionMode;
use PHPUnit\Framework\TestCase;

class PermissionModeTest extends TestCase
{
    public function test_enum_values(): void
    {
        $this->assertSame('guardian', PermissionMode::Guardian->value);
        $this->assertSame('argus', PermissionMode::Argus->value);
        $this->assertSame('prometheus', PermissionMode::Prometheus->value);
    }

    public function test_labels(): void
    {
        $this->assertSame('Guardian', PermissionMode::Guardian->label());
        $this->assertSame('Argus', PermissionMode::Argus->label());
        $this->assertSame('Prometheus', PermissionMode::Prometheus->label());
    }

    public function test_symbols(): void
    {
        $this->assertSame('◈', PermissionMode::Guardian->symbol());
        $this->assertSame('◉', PermissionMode::Argus->symbol());
        $this->assertSame('⚡', PermissionMode::Prometheus->symbol());
    }

    public function test_status_labels(): void
    {
        $this->assertSame('Guardian ◈', PermissionMode::Guardian->statusLabel());
        $this->assertSame('Argus ◉', PermissionMode::Argus->statusLabel());
        $this->assertSame('Prometheus ⚡', PermissionMode::Prometheus->statusLabel());
    }

    public function test_from_string(): void
    {
        $this->assertSame(PermissionMode::Guardian, PermissionMode::from('guardian'));
        $this->assertSame(PermissionMode::Argus, PermissionMode::from('argus'));
        $this->assertSame(PermissionMode::Prometheus, PermissionMode::from('prometheus'));
    }
}
