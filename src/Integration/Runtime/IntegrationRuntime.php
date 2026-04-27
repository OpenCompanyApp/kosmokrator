<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\KosmokratorLuaToolInvoker;
use Kosmokrator\Lua\LuaDocService;
use Kosmokrator\Lua\LuaSandboxService;
use Kosmokrator\Lua\NativeToolBridge;
use OpenCompany\IntegrationCore\Lua\LuaBridge;

final class IntegrationRuntime
{
    public function __construct(
        private readonly IntegrationCatalog $catalog,
        private readonly IntegrationManager $integrationManager,
        private readonly LuaSandboxService $lua,
        private readonly LuaDocService $docService,
        private readonly IntegrationDocService $integrationDocs,
        private readonly KosmokratorLuaToolInvoker $invoker,
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

        $provider = $this->integrationManager->getLocallyRunnableProviders()[$function->provider] ?? null;
        if ($provider === null) {
            throw new \RuntimeException("Integration '{$function->provider}' is not locally runnable.");
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
        $bridge = null;
        if ($this->integrationManager->getActiveProviders() !== []) {
            $bridge = new LuaBridge(
                $this->docService->buildFunctionMap(),
                $this->docService->buildParameterMap(),
                $this->invoker,
                $this->docService->buildAccountMap(),
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

        $force = (bool) ($options['force'] ?? false);
        unset($options['force']);

        $result = $this->invoker->runWithForce(
            $force,
            fn () => $this->lua->execute($code, $options, $bridge, nativeBridge: $nativeToolBridge, phpFunctions: $phpFunctions),
        );

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
