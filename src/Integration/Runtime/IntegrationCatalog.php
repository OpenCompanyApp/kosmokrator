<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use Kosmokrator\Integration\IntegrationManager;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;
use OpenCompany\IntegrationCore\Support\ToolProviderRegistry;

final class IntegrationCatalog
{
    /** @var array<string, IntegrationFunction>|null */
    private ?array $functions = null;

    public function __construct(
        private readonly ToolProviderRegistry $providers,
        private readonly IntegrationManager $integrationManager,
        private readonly LuaCatalogBuilder $catalogBuilder,
    ) {}

    /**
     * @return array<string, IntegrationFunction>
     */
    public function functions(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }

        $active = $this->integrationManager->getActiveProviders();
        $functions = [];

        foreach ($this->integrationManager->getDiscoverableProviders() as $providerName => $provider) {
            $configured = $this->integrationManager->isConfiguredForActivation($providerName, $provider);
            $accounts = $this->integrationManager->getAccounts($providerName);
            $capabilities = $this->integrationManager->capabilityMetadata($provider);

            foreach ($provider->tools() as $slug => $meta) {
                $toolClass = (string) ($meta['class'] ?? '');
                if ($toolClass === '') {
                    continue;
                }

                $title = (string) ($meta['name'] ?? $slug);
                $function = $this->catalogBuilder->deriveFunctionName($title, $providerName);
                $description = (string) ($meta['description'] ?? '');
                $parameters = [];

                try {
                    if (! isset($active[$providerName])) {
                        throw new \RuntimeException('Provider inactive.');
                    }

                    $tool = $provider->createTool($toolClass);
                    $parameters = $tool->parameters();
                    $toolDescription = trim($tool->description());
                    if ($toolDescription !== '') {
                        $description = $toolDescription;
                    }
                } catch (\Throwable) {
                    // Keep discovery usable even when a provider cannot instantiate
                    // without credentials or optional dependencies.
                }

                $entry = new IntegrationFunction(
                    provider: $providerName,
                    function: $function,
                    slug: $slug,
                    title: $title,
                    description: $description,
                    operation: (string) ($meta['type'] ?? 'read'),
                    parameters: $parameters,
                    meta: $meta,
                    toolProvider: $provider,
                    toolClass: $toolClass,
                    active: isset($active[$providerName]),
                    configured: $configured,
                    accounts: $accounts,
                    capabilities: $capabilities,
                );

                $functions[$entry->fullName()] = $entry;
            }
        }

        ksort($functions, SORT_STRING);

        return $this->functions = $functions;
    }

    public function get(string $name): ?IntegrationFunction
    {
        return $this->functions()[$name] ?? null;
    }

    public function clearCache(): void
    {
        $this->functions = null;
    }

    public function hydrate(IntegrationFunction $function): IntegrationFunction
    {
        if ($function->parameters !== []) {
            return $function;
        }

        $description = $function->description;
        $parameters = [];

        try {
            $ref = new \ReflectionClass($function->toolClass);
            $tool = $ref->newInstanceWithoutConstructor();
            if (method_exists($tool, 'parameters')) {
                $parameters = $tool->parameters();
            }
            if (method_exists($tool, 'description')) {
                $toolDescription = trim($tool->description());
                if ($toolDescription !== '') {
                    $description = $toolDescription;
                }
            }
        } catch (\Throwable) {
            return $function;
        }

        return new IntegrationFunction(
            provider: $function->provider,
            function: $function->function,
            slug: $function->slug,
            title: $function->title,
            description: $description,
            operation: $function->operation,
            parameters: $parameters,
            meta: $function->meta,
            toolProvider: $function->toolProvider,
            toolClass: $function->toolClass,
            active: $function->active,
            configured: $function->configured,
            accounts: $function->accounts,
            capabilities: $function->capabilities,
        );
    }

    /**
     * @return array<string, list<IntegrationFunction>>
     */
    public function byProvider(): array
    {
        $result = [];

        foreach ($this->functions() as $function) {
            $result[$function->provider][] = $function;
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->byProvider());
    }

    /**
     * @return list<IntegrationFunction>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $scored = [];
        foreach ($this->functions() as $function) {
            $haystack = strtolower($function->fullName().' '.$function->title.' '.$function->description);
            $score = 0;
            if (str_contains($function->fullName(), $query)) {
                $score += 100;
            }
            if (str_contains(strtolower($function->title), $query)) {
                $score += 50;
            }
            if (str_contains($haystack, $query)) {
                $score += 10;
            }
            foreach (preg_split('/\s+/', $query) ?: [] as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    $score += 3;
                }
            }
            if ($score > 0) {
                $scored[] = [$score, $function];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: strcmp($a[1]->fullName(), $b[1]->fullName()));

        return array_map(
            static fn (array $row): IntegrationFunction => $row[1],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * @return list<string>
     */
    public function locallyRunnableProviderNames(): array
    {
        $names = array_keys($this->integrationManager->getLocallyRunnableProviders());
        sort($names, SORT_STRING);

        return $names;
    }
}
