<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Tool\AskChoiceTool;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AskChoiceToolTest extends TestCase
{
    public function test_execute_passes_recommended_choice_metadata_to_renderer(): void
    {
        $ui = $this->createMock(RendererInterface::class);
        $tool = new AskChoiceTool($ui);

        $choices = [
            ['label' => 'User docs only', 'detail' => 'Only user-facing docs stay in scope.', 'recommended' => true],
            ['label' => 'All docs', 'detail' => null],
        ];

        $ui->expects($this->once())
            ->method('askChoice')
            ->with(
                'Which docs are in scope?',
                [
                    ['label' => 'User docs only', 'detail' => 'Only user-facing docs stay in scope.', 'recommended' => true],
                    ['label' => 'All docs', 'detail' => null, 'recommended' => false],
                ]
            )
            ->willReturn('User docs only');

        $result = $tool->execute([
            'question' => 'Which docs are in scope?',
            'choices' => json_encode($choices, JSON_THROW_ON_ERROR),
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('User docs only', $result->output);
    }

    public function test_execute_rejects_empty_choices(): void
    {
        $ui = $this->createMock(RendererInterface::class);
        $tool = new AskChoiceTool($ui);

        $result = $tool->execute([
            'question' => 'Pick one',
            'choices' => '[]',
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('choices must be a non-empty JSON array', $result->output);
    }
}
