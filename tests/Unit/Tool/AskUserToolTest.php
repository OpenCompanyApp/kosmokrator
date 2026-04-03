<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Tool;

use Kosmokrator\Tool\AskUserTool;
use Kosmokrator\UI\RendererInterface;
use PHPUnit\Framework\TestCase;

class AskUserToolTest extends TestCase
{
    private RendererInterface $ui;
    private AskUserTool $tool;

    protected function setUp(): void
    {
        $this->ui = $this->createMock(RendererInterface::class);
        $this->tool = new AskUserTool($this->ui);
    }

    public function test_name_returns_ask_user(): void
    {
        $this->assertSame('ask_user', $this->tool->name());
    }

    public function test_description_returns_non_empty_string(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_parameters_has_question_key(): void
    {
        $params = $this->tool->parameters();
        $this->assertArrayHasKey('question', $params);
    }

    public function test_requiredParameters_contains_question(): void
    {
        $this->assertContains('question', $this->tool->requiredParameters());
    }

    public function test_execute_with_valid_question_calls_askUser_and_returns_success(): void
    {
        $this->ui->expects($this->once())
            ->method('askUser')
            ->with('What is the answer?')
            ->willReturn('42');

        $result = $this->tool->execute(['question' => 'What is the answer?']);

        $this->assertTrue($result->success);
        $this->assertSame('42', $result->output);
    }

    public function test_execute_with_empty_question_returns_error(): void
    {
        $result = $this->tool->execute(['question' => '']);

        $this->assertFalse($result->success);
        $this->assertSame('No question provided', $result->output);
    }

    public function test_execute_with_no_question_key_returns_error(): void
    {
        $result = $this->tool->execute([]);

        $this->assertFalse($result->success);
        $this->assertSame('No question provided', $result->output);
    }

    public function test_execute_when_user_returns_empty_string_returns_no_response(): void
    {
        $this->ui->method('askUser')->willReturn('');

        $result = $this->tool->execute(['question' => 'Anything?']);

        $this->assertTrue($result->success);
        $this->assertSame('(no response)', $result->output);
    }
}
