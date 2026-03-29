<?php

namespace Kosmokrator\UI;

interface RendererInterface
{
    public function initialize(): void;

    public function renderIntro(bool $animated): void;

    public function prompt(): string;

    public function showThinking(): void;

    public function streamChunk(string $text): void;

    public function streamComplete(): void;

    public function showToolCall(string $name, array $args): void;

    public function showToolResult(string $name, string $output, bool $success): void;

    public function showError(string $message): void;

    public function showStatus(string $model, int $tokensIn, int $tokensOut, float $cost): void;

    public function teardown(): void;
}
