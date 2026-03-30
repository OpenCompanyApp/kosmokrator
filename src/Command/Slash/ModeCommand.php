<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Slash;

use Kosmokrator\Agent\AgentMode;
use Kosmokrator\Command\SlashCommand;
use Kosmokrator\Command\SlashCommandContext;
use Kosmokrator\Command\SlashCommandResult;

class ModeCommand implements SlashCommand
{
    public function __construct(
        private readonly AgentMode $mode,
    ) {}

    public function name(): string
    {
        return '/' . $this->mode->value;
    }

    /** @return string[] */
    public function aliases(): array
    {
        return [];
    }

    public function description(): string
    {
        return "Switch to {$this->mode->label()} mode";
    }

    public function execute(string $args, SlashCommandContext $ctx): SlashCommandResult
    {
        $ctx->agentLoop->setMode($this->mode);
        $ctx->ui->showMode($this->mode->label(), $this->mode->color());
        $ctx->sessionManager->setSetting('mode', $this->mode->value);
        $ctx->ui->showNotice("Switched to {$this->mode->label()} mode.");

        return SlashCommandResult::continue();
    }
}
