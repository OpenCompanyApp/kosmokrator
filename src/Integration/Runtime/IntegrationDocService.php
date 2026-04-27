<?php

declare(strict_types=1);

namespace Kosmokrator\Integration\Runtime;

final class IntegrationDocService
{
    public function __construct(
        private readonly IntegrationCatalog $catalog,
    ) {}

    public function render(?string $page = null, string $format = 'text'): string
    {
        if ($format === 'json') {
            return $this->json($page);
        }

        if ($page === null || $page === '') {
            return $this->renderIndex();
        }

        $function = $this->catalog->get($page);
        if ($function !== null) {
            return $this->renderFunction($this->catalog->hydrate($function));
        }

        $providers = $this->catalog->byProvider();
        if (isset($providers[$page])) {
            return $this->renderProvider($page, $providers[$page]);
        }

        return "No integration docs found for '{$page}'.";
    }

    private function renderIndex(): string
    {
        $lines = ['Integration CLI', ''];
        $lines[] = 'Use direct CLI calls for single operations and Lua for multi-step workflows.';
        $lines[] = '';
        $lines[] = 'Commands:';
        $lines[] = '  kosmokrator integrations:list';
        $lines[] = '  kosmokrator integrations:search "clickup task"';
        $lines[] = '  kosmokrator integrations:docs clickup.create_task';
        $lines[] = '  kosmokrator integrations:call clickup.create_task --list-id=123 --name="Ship it"';
        $lines[] = '  kosmokrator integrations:lua workflow.lua';
        $lines[] = '';
        $lines[] = 'Providers: '.implode(', ', $this->catalog->providers());

        return implode("\n", $lines);
    }

    /**
     * @param  list<IntegrationFunction>  $functions
     */
    private function renderProvider(string $provider, array $functions): string
    {
        $active = $functions[0]->active ?? false;
        $configured = $functions[0]->configured ?? false;
        $accounts = $functions[0]->accounts ?? [];
        $lines = [$provider, ''];
        $lines[] = 'Status: '.($active ? 'active' : ($configured ? 'configured but disabled' : 'inactive'));
        $lines[] = 'Accounts: '.($accounts === [] ? 'default' : 'default, '.implode(', ', $accounts));
        $lines[] = '';
        $lines[] = 'Functions:';

        foreach ($functions as $function) {
            $lines[] = sprintf('  %-34s %s', $function->function, $this->oneLine($function->description));
        }

        $lines[] = '';
        $lines[] = 'Examples:';
        $lines[] = "  kosmokrator integrations:docs {$functions[0]->fullName()}";
        $lines[] = "  kosmokrator integrations:schema {$functions[0]->fullName()}";
        $lines[] = '  '.$this->directCliExample($functions[0]);

        return implode("\n", $lines);
    }

    private function renderFunction(IntegrationFunction $function): string
    {
        $lines = [$function->fullName(), ''];
        $lines[] = trim($function->description) !== '' ? trim($function->description) : $function->title;
        $lines[] = '';
        $lines[] = 'Operation: '.$function->operation;
        $lines[] = 'Status: '.($function->active ? 'active' : 'inactive');
        $lines[] = '';
        $lines[] = 'Direct CLI:';
        $lines[] = '  '.$this->directCliExample($function);
        $lines[] = '';
        $lines[] = 'Generic CLI:';
        $lines[] = '  '.$this->genericCliExample($function);
        $lines[] = '';
        $lines[] = 'JSON:';
        $lines[] = "  kosmokrator integrations:call {$function->fullName()} '".$this->sampleJson($function)."' --json";
        $lines[] = '';
        $lines[] = 'Lua:';
        $lines[] = '  local result = app.integrations.'.$function->provider.'.'.$function->function.'('.$this->sampleLuaTable($function).')';
        $lines[] = '';
        $lines[] = 'Parameters:';

        if ($function->parameters === []) {
            $lines[] = '  none';
        } else {
            foreach ($function->parameters as $name => $schema) {
                $required = ($schema['required'] ?? false) === true ? 'required' : 'optional';
                $type = (string) ($schema['type'] ?? 'string');
                $description = $this->oneLine((string) ($schema['description'] ?? ''));
                $lines[] = sprintf('  %-24s %-9s %-9s %s', $name, $type, $required, $description);
            }
        }

        return implode("\n", $lines);
    }

    private function directCliExample(IntegrationFunction $function): string
    {
        return "kosmokrator integrations:{$function->provider} {$function->function} ".$this->sampleFlags($function);
    }

    private function genericCliExample(IntegrationFunction $function): string
    {
        return "kosmokrator integrations:call {$function->fullName()} ".$this->sampleFlags($function);
    }

    private function sampleFlags(IntegrationFunction $function): string
    {
        $parts = [];
        foreach ($function->parameters as $name => $schema) {
            if (($schema['required'] ?? false) !== true) {
                continue;
            }
            $parts[] = '--'.str_replace('_', '-', $name).'='.$this->sampleValue((string) ($schema['type'] ?? 'string'), $name);
        }

        return implode(' ', $parts);
    }

    private function sampleJson(IntegrationFunction $function): string
    {
        $data = [];
        foreach ($function->parameters as $name => $schema) {
            if (($schema['required'] ?? false) === true) {
                $data[$name] = $this->sampleValue((string) ($schema['type'] ?? 'string'), $name);
            }
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function sampleLuaTable(IntegrationFunction $function): string
    {
        $parts = [];
        foreach ($function->parameters as $name => $schema) {
            if (($schema['required'] ?? false) === true) {
                $type = (string) ($schema['type'] ?? 'string');
                $value = $this->sampleValue($type, $name);
                $parts[] = "{$name} = ".(($type === 'integer' || $type === 'number' || $type === 'boolean') ? $value : '"'.$value.'"');
            }
        }

        return '{ '.implode(', ', $parts).' }';
    }

    private function sampleValue(string $type, string $name): string
    {
        if ($type === 'integer' || $type === 'number') {
            return '123';
        }

        if ($type === 'boolean') {
            return 'true';
        }

        return str_ends_with($name, '_id') ? '123' : 'value';
    }

    private function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function json(?string $page): string
    {
        if ($page !== null && $page !== '') {
            $function = $this->catalog->get($page);
            if ($function !== null) {
                return json_encode($this->catalog->hydrate($function)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            }

            $providers = $this->catalog->byProvider();
            if (isset($providers[$page])) {
                return json_encode(array_map(
                    static fn (IntegrationFunction $function): array => $function->toArray(),
                    $providers[$page],
                ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
        }

        return json_encode(array_map(
            static fn (IntegrationFunction $function): array => $function->toArray(),
            array_values($this->catalog->functions()),
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
