<?php

declare(strict_types=1);

namespace Kosmokrator\LLM\Codex;

interface CodexTokenStore
{
    public function current(): ?CodexToken;

    public function save(CodexToken $token): CodexToken;

    public function clear(): void;
}
