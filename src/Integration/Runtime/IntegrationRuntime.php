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
    public function call(string $name, array $args, ?string $account = null): IntegrationCallResult
    {
        $function = $this->catalog->get($name);
        if ($function === null) {
            throw new \RuntimeException("Unknown integration function: {$name}");
        }

        if (! array_key_exists($function->provider, $this->integrationManager->getActiveProviders())) {
            throw new \RuntimeException("Integration '{$function->provider}' is installed but not active. Enable and configure it in settings first.");
        }

        $this->assertRequiredParameters($function, $args);

        $start = microtime(true);
        try {
            $data = $this->invoker->invoke($function->slug, $args, $account);
        } catch (\Throwable $e) {
            return new IntegrationCallResult(
                function: $function->fullName(),
                data: null,
                success: false,
                error: $e->getMessage(),
                durationMs: round((microtime(true) - $start) * 1000, 1),
            );
        }

        return new IntegrationCallResult(
            function: $function->fullName(),
            data: $data,
            success: true,
            durationMs: round((microtime(true) - $start) * 1000, 1),
        );
    }

    /**
     * Execute Lua with integration namespaces and docs helpers.
     *
     * @param  array{memoryLimit?: int, cpuLimit?: float}  $options
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

        $result = $this->lua->execute($code, $options, $bridge, nativeBridge: $nativeToolBridge, phpFunctions: $phpFunctions);

        return new LuaExecutionResult(
            lua: $result,
            callLog: $bridge?->getCallLog() ?? [],
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
