# Lazy Integration Boot

> Status: Proposal

## Problem

`IntegrationServiceProvider::boot()` eagerly discovers and boots all ~444 integration packages at startup. Each package's ServiceProvider is instantiated, `register()` and `boot()` are called, and the ToolProvider is constructed with all its tool class imports.

**Measured cost: 17.2 MB** — the single largest allocation after autoload. For sessions that never touch integrations (pure coding tasks), this is pure waste.

### Current boot flow

```
IntegrationServiceProvider::boot()
  └─ discoverIntegrations()
       ├─ parse composer.lock (fast, ~1ms)
       ├─ parse local monorepo composer.json files (fast)
       └─ for each of ~444 matching packages:
            ├─ new XxxServiceProvider($container)   ← autoloads class + deps
            ├─ ->register()                         ← binds singleton closure (cheap)
            └─ ->boot()                             ← new XxxToolProvider() + registers
                                                      into ToolProviderRegistry (expensive:
                                                      imports all tool classes via use statements)
```

### Memory profile

```
Before IntegrationServiceProvider::boot():  15.6 MB
After IntegrationServiceProvider::boot():   32.8 MB
                                            -------
Cost:                                       17.2 MB
```

## Proposed solution

Split boot into two phases: **discover** (cheap) and **materialize** (expensive, on-demand).

### Phase 1: Discover at boot — build manifest only

At boot, parse composer.lock + monorepo to collect a lightweight manifest:

```php
// ~50-100 KB instead of 17 MB
$manifest = [
    'opencompanyapp/integration-slack' => [
        'providers' => ['OpenCompany\Integrations\Slack\SlackServiceProvider'],
        'dir' => null,  // non-null for local monorepo packages
    ],
    'opencompanyapp/integration-github' => [
        'providers' => ['OpenCompany\Integrations\Github\GithubServiceProvider'],
        'dir' => '/Users/.../integrations/packages/github',
    ],
    // ... ~442 more entries
];
```

This reuses the existing `discoverIntegrations()` logic but stops before `new $providerClass()`. The manifest captures package name, provider class FQCNs, and optional local directory (for monorepo autoload registration).

### Phase 2: Materialize on first access

The first call to any of these triggers full boot of all pending packages:

- `IntegrationManager::getAllProviders()`
- `IntegrationManager::getActiveProviders()`
- `IntegrationManager::getToolCatalog()`
- `ToolProviderRegistry::all()`
- `LuaDocService` catalog building

### Where to put the lazy gate

`IntegrationManager` is the natural boundary — every runtime consumer goes through it. Add an `ensureBooted()` guard:

```php
class IntegrationManager
{
    private bool $booted = false;

    /** @var null|array<string, array{providers: list<string>, dir: ?string}> */
    private ?array $pendingManifest = null;

    public function setPendingManifest(array $manifest): void
    {
        $this->pendingManifest = $manifest;
    }

    private function ensureBooted(): void
    {
        if ($this->booted || $this->pendingManifest === null) {
            return;
        }

        $this->booted = true;

        foreach ($this->pendingManifest as $name => $entry) {
            // Register local package autoload if needed
            if ($entry['dir'] !== null) {
                $this->registerLocalAutoload($name, $entry['dir']);
            }

            foreach ($entry['providers'] as $providerClass) {
                if (!class_exists($providerClass)) {
                    continue;
                }
                try {
                    $provider = new $providerClass($this->container);
                    $provider->register();
                    $provider->boot();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $this->pendingManifest = null;
    }

    public function getAllProviders(): array
    {
        $this->ensureBooted();
        return $this->providers->all();
    }

    // Same guard in getActiveProviders(), getToolCatalog(), etc.
}
```

### Changes to IntegrationServiceProvider

```php
public function boot(): void
{
    // Build manifest (cheap: JSON parse + string matching, no class loading)
    $manifest = $this->buildManifest();

    // Hand it to IntegrationManager for lazy materialization
    $this->container->resolving(IntegrationManager::class, function (IntegrationManager $mgr) use ($manifest) {
        $mgr->setPendingManifest($manifest);
    });
}

private function buildManifest(): array
{
    // Same discovery logic as current discoverIntegrations(),
    // but returns array instead of calling registerIntegrationPackage()
    $manifest = [];

    // ... parse composer.lock, filter by prefix, skip redundant, etc.
    // For each matching package:
    //   $manifest[$name] = ['providers' => $providerClasses, 'dir' => $packageDir];

    return $manifest;
}
```

## What doesn't change

- Integration packages themselves are untouched
- `ToolProviderRegistry` contract unchanged
- Settings UI, credential resolver, permission system — all unchanged
- Sessions that DO use integrations pay the same cost, just deferred to first use

## Expected impact

- **Startup: -17 MB** for sessions that never touch integrations
- **First integration access: +17 MB** (same cost, just deferred)
- **Manifest overhead: ~50-100 KB** (444 entries × ~100 bytes)
- No behavioral change — integrations work identically once booted

## Edge cases

- **/settings listing integrations**: Triggers boot. This is fine — the user explicitly asked to see integrations.
- **System prompt building**: If the system prompt references active integrations (tool catalog), this triggers boot during the first LLM call. Acceptable — the cost is paid once.
- **Subagents**: If subagents share the same container, integrations are booted once globally. If they create fresh containers, each would pay the boot cost. Current architecture shares the container, so no issue.
- **Local monorepo autoload**: The custom PSR-4 autoloader for local packages must be registered lazily too (inside `ensureBooted`), not at manifest-build time.

## Alternatives considered

**Per-integration lazy loading** (only boot slack when slack tools are invoked): More granular savings but significantly more complex. Would require a proxy ToolProvider that defers to the real one. The all-or-nothing approach is simpler and captures 95%+ of the benefit since most sessions either use integrations or don't.

**Cache the manifest to disk**: Not worth it — building the manifest from composer.lock is fast (~5ms). The expensive part is instantiating 444 ServiceProviders, which can't be cached.
