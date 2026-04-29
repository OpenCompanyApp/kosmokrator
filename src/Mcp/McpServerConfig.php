<?php

declare(strict_types=1);

namespace Kosmokrator\Mcp;

final readonly class McpServerConfig
{
    /**
     * @param  list<string>  $args
     * @param  array<string, string>  $env
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $command = null,
        public array $args = [],
        public ?string $url = null,
        public array $env = [],
        public array $headers = [],
        public bool $enabled = true,
        public int $timeoutSeconds = 30,
        public string $source = 'unknown',
        public ?string $path = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $name, array $data, string $source = 'unknown', ?string $path = null): self
    {
        $url = self::stringOrNull($data['url'] ?? $data['serverUrl'] ?? null);
        $command = self::stringOrNull($data['command'] ?? null);
        $type = self::stringOrNull($data['type'] ?? null);

        if ($type === null || $type === 'local') {
            $type = $url !== null ? 'http' : 'stdio';
        }

        $args = array_values(array_map('strval', is_array($data['args'] ?? null) ? $data['args'] : []));
        $env = self::stringMap($data['env'] ?? $data['environment'] ?? []);
        $headers = self::stringMap($data['headers'] ?? []);
        $enabled = ! array_key_exists('enabled', $data) || $data['enabled'] === true || $data['enabled'] === 'true' || $data['enabled'] === 'on';
        $timeout = (int) ($data['timeout'] ?? $data['timeoutSeconds'] ?? 30);

        return new self(
            name: $name,
            type: $type,
            command: $command,
            args: $args,
            url: $url,
            env: $env,
            headers: $headers,
            enabled: $enabled,
            timeoutSeconds: $timeout > 0 ? $timeout : 30,
            source: $source,
            path: $path,
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPortableArray(): array
    {
        $data = [];

        if ($this->type !== 'stdio') {
            $data['type'] = $this->type;
        }

        if ($this->type === 'stdio') {
            $data['command'] = $this->command;
            if ($this->args !== []) {
                $data['args'] = $this->args;
            }
            if ($this->env !== []) {
                $data['env'] = $this->env;
            }
        } else {
            $data['url'] = $this->url;
            if ($this->headers !== []) {
                $data['headers'] = $this->headers;
            }
        }

        if (! $this->enabled) {
            $data['enabled'] = false;
        }

        if ($this->timeoutSeconds !== 30) {
            $data['timeout'] = $this->timeoutSeconds;
        }

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }

    public function functionPrefix(): string
    {
        return 'mcp_'.self::sanitizeIdentifier($this->name).'__';
    }

    public static function sanitizeIdentifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? 'server' : $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = is_scalar($item) || $item === null ? (string) $item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $result;
    }
}
