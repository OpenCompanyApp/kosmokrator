<?php

declare(strict_types=1);

namespace Kosmokrator\Sdk;

final class AgentConversation
{
    /** @var list<AgentResult> */
    private array $turns = [];

    public function __construct(private readonly Agent $agent) {}

    public function send(string $prompt): AgentResult
    {
        $result = $this->agent->collect($prompt);
        $this->turns[] = $result;

        return $result;
    }

    /** @return list<AgentResult> */
    public function turns(): array
    {
        return $this->turns;
    }
}
