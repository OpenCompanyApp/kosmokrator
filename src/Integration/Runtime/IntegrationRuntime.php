<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\LuaSandboxService;
use Kosmokrator\Lua\NativeToolBridge;
use Kosmokrator\Mcp\CompositeLuaToolInvoker;
use Kosmokrator\Mcp\McpCatalog;
use Kosmokrator\Mcp\McpLuaToolInvoker;
use Kosmokrator\Mcp\McpRuntime;
use OpenCompany\IntegrationCore\Lua\LuaBridge;
use OpenCompany\IntegrationCore\Lua\LuaCatalogBuilder;

final class IntegrationRuntime
{
    public function __construct(
        private readonly IntegrationCatalog $catalog,
        private readonly IntegrationManager $integrationManager,
        private readonly LuaSandboxService $lua,
        private readonly LuaDocService $docService,
        private readonly IntegrationDocService $integrationDocs,
        private readonly KosmokratorLuaToolInvoker $invoker,
        private readonly ?McpCatalog $mcpCatalog = null,
        private readonly ?McpLuaToolInvoker $mcpInvoker = null,
        private readonly ?McpRuntime $mcpRuntime = null,
    ) {}

    public function catalog(): IntegrationCatalog
    {
        return $this->catalog;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function call(string $name, array $args, ?string $account = null, ?IntegrationRuntimeOptions $options = null): IntegrationCallResult
    {
        $options ??= new IntegrationRuntimeOptions(account: $account);
        if ($options->account === null && $account !== null) {
            $options = new IntegrationRuntimeOptions(account: $account, force: $options->force, dryRun: $options->dryRun);
        }

        $function = $this->catalog->get($name);
        if ($function === null) {
            throw new \RuntimeException("Unknown integration function: {$name}");
        }

        $provider = $this->integrationManager->getDiscoverableProviders()[$function->provider] ?? null;
        if ($provider === null) {
            throw new \RuntimeException("Integration '{$function->provider}' is not installed.");
        }

        if (! $this->integrationManager->isCliRuntimeSupported($provider)) {
            throw new \RuntimeException("Integration '{$function->provider}' is discoverable but is not supported by the local CLI runtime yet.");
        }

        if (! $this->integrationManager->isEnabled($function->provider)) {
            throw new \RuntimeException("Integration '{$function->provider}' is installed but not active. Enable it in settings first.");
        }

        if (! $this->integrationManager->isConfiguredForActivation($function->provider, $provider, $options->account)) {
            $accountLabel = $options->account === null ? 'default' : $options->account;
            throw new \RuntimeException("Integration '{$function->provider}' is missing required credentials for account '{$accountLabel}'. Configure it in settings first.");
        }

        $this->assertRequiredParameters($function, $args);

        $start = microtime(true);
        try {
            $this->invoker->assertCanInvoke($function->slug, $options->force);
            if ($options->dryRun) {
                return new IntegrationCallResult(
                    function: $function->fullName(),
                    data: null,
                    success: true,
                    meta: [
                        'dry_run' => true,
                        'operation' => $function->operation,
                        'permission_bypassed' => $options->force,
                        'account' => $options->account,
                    ],
                    durationMs: round((microtime(true) - $start) * 1000, 1),
                );
            }

            $data = $this->invoker->invoke($function->slug, $args, $options->account, $options->force);
        } catch (\Throwable $e) {
            return new IntegrationCallResult(
                function: $function->fullName(),
                data: null,
                success: false,
                error: $e->getMessage(),
                meta: [
                    'dry_run' => $options->dryRun,
                    'permission_bypassed' => $options->force,
                    'account' => $options->account,
                ],
                durationMs: round((microtime(true) - $start) * 1000, 1),
            );
        }

        return new IntegrationCallResult(
            function: $function->fullName(),
            data: $data,
            success: true,
            meta: [
                'dry_run' => false,
                'operation' => $function->operation,
                'permission_bypassed' => $options->force,
                'account' => $options->account,
            ],
            durationMs: round((microtime(true) - $start) * 1000, 1),
        );
    }

    /**
     * Execute Lua with integration namespaces and docs helpers.
     *
     * @param  array{memoryLimit?: int, cpuLimit?: float, force?: bool}  $options
     */
    public function executeLua(string $code, array $options = [], ?NativeToolBridge $nativeToolBridge = null): LuaExecutionResult
    {
        $force = (bool) ($options['force'] ?? false);
        unset($options['force']);

        $bridge = null;
        $functionMap = $this->docService->buildFunctionMap();
        $parameterMap = $this->docService->buildParameterMap();
        $accountMap = $this->docService->buildAccountMap();
        $mcpNamespaces = $this->mcpRuntime?->luaNamespaces($force) ?? [];

        if ($mcpNamespaces !== []) {
            $builder = new LuaCatalogBuilder;
            $functionMap = array_merge($functionMap, $builder->buildFunctionMap($mcpNamespaces));
            $parameterMap = array_merge($parameterMap, $builder->buildParameterMap($mcpNamespaces));
        }

        if ($functionMap !== []) {
            $bridge = new LuaBridge(
                $functionMap,
                $parameterMap,
                $this->mcpInvoker === null ? $this->invoker : new CompositeLuaToolInvoker($this->invoker, $this->mcpInvoker),
                $accountMap,
            );
        }

        $phpFunctions = [
            'docs.list' => fn () => $this->integrationDocs->render(),
            'docs.search' => fn (string $query) => implode("\n", array_map(
                static fn (IntegrationFunction $function): string => $function->fullName().' - '.$function->description,
                $this->catalog->search($query),
            )),
            'docs.read' => fn (string $page) => $this->integrationDocs->render($page),
        ];
        if ($this->mcpRuntime !== null) {
            $phpFunctions = array_merge($phpFunctions, $this->mcpRuntime->helperFunctions($force));
        }

        $execute = fn () => $this->lua->execute($code, $options, $bridge, nativeBridge: $nativeToolBridge, phpFunctions: $phpFunctions);
        $result = $this->mcpInvoker === null
            ? $this->invoker->runWithForce($force, $execute)
            : $this->mcpInvoker->runWithForce($force, fn () => $this->invoker->runWithForce($force, $execute));

        return new LuaExecutionResult(
            lua: $result,
            callLog: $bridge?->getCallLog() ?? [],
            meta: ['permission_bypassed' => $force],
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function assertRequiredParameters(IntegrationFunction $function, array $args): void
    {
        $missing = [];

        foreach ($function->requiredParameters() as $parameter) {
            if (! array_key_exists($parameter, $args) || $args[$parameter] === '' || $args[$parameter] === null) {
                $missing[] = $parameter;
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException('Missing required parameter(s): '.implode(', ', $missing));
        }
    }
}
