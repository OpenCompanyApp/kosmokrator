<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp\Server;

final class McpGatewayResultFormatter
{
    /**
     * @return array<string, mixed>
     */
    public function success(mixed $data, int $maxChars): array
    {
        $text = $this->toText($data);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->truncate($text, $maxChars),
                ],
            ],
            'structuredContent' => is_array($data) ? $data : ['value' => $data],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function error(string $message, int $maxChars): array
    {
        return [
            'isError' => true,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->truncate($message, $maxChars),
                ],
            ],
        ];
    }

    private function toText(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? (string) $data : $json;
    }

    private function truncate(string $text, int $maxChars): string
    {
        if (strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, max(0, $maxChars - 34))."\n\n[truncated by KosmoKrator MCP gateway]";
    }
}
