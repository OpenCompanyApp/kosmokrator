<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Mcp;

interface McpToolInvokerInterface
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|list<mixed>|string
     */
    public function call(string $remoteUrl, string $toolName, array $arguments, array $headers = []): array|string;
}
