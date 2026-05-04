<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use OpenCompany\IntegrationCore\Contracts\ToolProvider;

final class IntegrationToolMetadata
{
    /**
     * @return array<string, array{class: string, type: string, name: string, description: string, icon: string, parameters?: array<string, mixed>}>
     */
    public static function forProvider(ToolProvider $provider): array
    {
        $tools = [];

        foreach ($provider->tools() as $key => $meta) {
            $normalised = self::normalise((string) $key, $meta, $provider->appName());
            if ($normalised === null) {
                continue;
            }

            $tools[(string) $normalised['slug']] = [
                'class' => (string) $normalised['class'],
                'type' => (string) $normalised['type'],
                'name' => (string) $normalised['name'],
                'description' => (string) $normalised['description'],
                'icon' => (string) $normalised['icon'],
                'parameters' => is_array($normalised['parameters'] ?? null) ? $normalised['parameters'] : [],
            ];
        }

        ksort($tools, SORT_STRING);

        return $tools;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function normalise(string $key, mixed $meta, string $providerName): ?array
    {
        if (is_string($meta) && class_exists($meta)) {
            return self::fromClass($key, $meta, $providerName);
        }

        if (! is_array($meta)) {
            return null;
        }

        $class = (string) ($meta['class'] ?? '');
        if ($class === '') {
            return null;
        }

        $slug = $key !== '' ? $key : self::fallbackSlug($class, $providerName);
        $type = (string) ($meta['type'] ?? self::inferOperation($slug));

        return [
            'slug' => $slug,
            'class' => $class,
            'type' => in_array($type, ['read', 'write'], true) ? $type : self::inferOperation($slug),
            'name' => (string) ($meta['name'] ?? self::titleFromSlug($slug, $providerName)),
            'description' => (string) ($meta['description'] ?? ''),
            'icon' => (string) ($meta['icon'] ?? 'ph:wrench'),
            'parameters' => is_array($meta['parameters'] ?? null) ? $meta['parameters'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromClass(string $key, string $class, string $providerName): array
    {
        $slug = self::fallbackSlug($class, $providerName);
        $description = '';
        $parameters = [];

        try {
            $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            if (method_exists($tool, 'name')) {
                $toolSlug = $tool->name();
                if (is_string($toolSlug) && $toolSlug !== '') {
                    $slug = $toolSlug;
                }
            }
            if (method_exists($tool, 'description')) {
                $toolDescription = $tool->description();
                $description = is_string($toolDescription) ? $toolDescription : '';
            }
            if (method_exists($tool, 'parameters')) {
                $toolParameters = $tool->parameters();
                $parameters = is_array($toolParameters) ? $toolParameters : [];
            }
        } catch (\Throwable) {
            if ($key !== '') {
                $slug = self::fallbackSlug($key, $providerName);
            }
        }

        return [
            'slug' => $slug,
            'class' => $class,
            'type' => self::inferOperation($slug),
            'name' => self::titleFromSlug($slug, $providerName),
            'description' => $description,
            'icon' => 'ph:wrench',
            'parameters' => $parameters,
        ];
    }

    private static function fallbackSlug(string $classOrKey, string $providerName): string
    {
        $base = str_contains($classOrKey, '\\') ? substr(strrchr($classOrKey, '\\') ?: $classOrKey, 1) : $classOrKey;
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            return $providerName.'_tool';
        }

        return str_starts_with($slug, $providerName.'_') ? $slug : $providerName.'_'.$slug;
    }

    private static function titleFromSlug(string $slug, string $providerName): string
    {
        $name = preg_replace('/^'.preg_quote($providerName, '/').'_/', '', $slug) ?? $slug;

        return ucwords(str_replace('_', ' ', $name));
    }

    private static function inferOperation(string $slug): string
    {
        $function = preg_replace('/^[a-z0-9]+_/', '', strtolower($slug)) ?? strtolower($slug);
        foreach (['get', 'list', 'search', 'count', 'download', 'fetch', 'find', 'lookup', 'retrieve', 'read'] as $prefix) {
            if ($function === $prefix || str_starts_with($function, $prefix.'_')) {
                return 'read';
            }
        }

        foreach (['analytics', 'billing', 'deliverability', 'status', 'stats', 'summary', 'progress', 'options', 'history', 'preview'] as $term) {
            if (str_contains($function, $term)) {
                return 'read';
            }
        }

        return 'write';
    }
}
