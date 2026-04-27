<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\IntegrationManager;
use Kosmokrator\Integration\YamlCredentialResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:configure', description: 'Configure integration credentials, activation, and permissions headlessly')]
final class IntegrationConfigureCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Integration provider')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account alias', 'default')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Credential key=value to store; repeatable')
            ->addOption('unset', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Credential key to remove; repeatable')
            ->addOption('stdin-json', null, InputOption::VALUE_NONE, 'Read configuration JSON from stdin')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable the integration')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable the integration')
            ->addOption('read', null, InputOption::VALUE_REQUIRED, 'Read permission: allow, ask, deny')
            ->addOption('write', null, InputOption::VALUE_REQUIRED, 'Write permission: allow, ask, deny')
            ->addOption('permissions', null, InputOption::VALUE_REQUIRED, 'Comma list such as read:allow,write:ask')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write activation and permissions to global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write activation and permissions to project config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providerName = (string) $input->getArgument('provider');
        $manager = $this->container->make(IntegrationManager::class);
        if (! isset($manager->getLocallyRunnableProviders()[$providerName])) {
            return $this->fail($input, $output, "Unknown locally runnable integration provider: {$providerName}");
        }

        $account = (string) ($input->getOption('account') ?: 'default');
        $accountArg = $account === 'default' ? null : $account;
        $scope = $input->getOption('project') ? 'project' : 'global';
        $credentials = $this->container->make(YamlCredentialResolver::class);
        try {
            $payload = $input->getOption('stdin-json') ? $this->stdinPayload() : [];
        } catch (\RuntimeException $exception) {
            return $this->fail($input, $output, $exception->getMessage());
        }

        $sets = $this->normaliseSets($input->getOption('set'), $payload['set'] ?? []);
        $unsets = $this->normaliseList($input->getOption('unset'), $payload['unset'] ?? []);
        $permissions = array_merge(
            $this->parsePermissions((string) ($input->getOption('permissions') ?? '')),
            $this->payloadPermissions($payload),
        );

        foreach (['read', 'write'] as $operation) {
            $value = $input->getOption($operation);
            if (is_string($value) && $value !== '') {
                $permissions[$operation] = $value;
            }
        }

        foreach ($permissions as $operation => $value) {
            if (! in_array($operation, ['read', 'write'], true) || ! in_array($value, ['allow', 'ask', 'deny'], true)) {
                return $this->fail($input, $output, "Invalid permission '{$operation}:{$value}'. Use read/write with allow, ask, or deny.");
            }
        }

        if ($sets !== []) {
            $credentials->registerAccount($providerName, $account);
            foreach ($sets as $key => $value) {
                $credentials->set($providerName, $key, (string) $value, $accountArg);
            }
        }

        foreach ($unsets as $key) {
            $credentials->delete($providerName, $key, $accountArg);
        }

        if ($input->getOption('enable') || ($payload['enabled'] ?? null) === true) {
            $manager->setEnabled($providerName, true, $scope);
        }
        if ($input->getOption('disable') || ($payload['enabled'] ?? null) === false) {
            $manager->setEnabled($providerName, false, $scope);
        }

        foreach ($permissions as $operation => $value) {
            $manager->setPermission($providerName, $operation, $value, $scope);
        }

        $data = [
            'success' => true,
            'provider' => $providerName,
            'account' => $account,
            'scope' => $scope,
            'credentials_set' => array_keys($sets),
            'credentials_unset' => $unsets,
            'enabled' => $manager->isEnabled($providerName),
            'configured' => $manager->isConfiguredForActivation($providerName, $manager->getLocallyRunnableProviders()[$providerName]),
            'permissions' => [
                'read' => $manager->getPermission($providerName, 'read'),
                'write' => $manager->getPermission($providerName, 'write'),
            ],
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $data);
        } else {
            $output->writeln("Configured {$providerName} ({$account}).");
        }

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => false, 'error' => $message]);
        } else {
            $output->writeln("<error>{$message}</error>");
        }

        return Command::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function stdinPayload(): array
    {
        $stdin = $this->readStdinIfPiped();
        if ($stdin === null) {
            return [];
        }

        $decoded = json_decode($stdin, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON supplied on stdin.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseSets(mixed $optionSets, mixed $payloadSets): array
    {
        $sets = is_array($payloadSets) ? $payloadSets : [];
        foreach (is_array($optionSets) ? $optionSets : [] as $entry) {
            [$key, $value] = array_pad(explode('=', (string) $entry, 2), 2, '');
            if ($key !== '') {
                $sets[$key] = $value;
            }
        }

        return $sets;
    }

    /**
     * @return list<string>
     */
    private function normaliseList(mixed $optionValues, mixed $payloadValues): array
    {
        return array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(is_array($payloadValues) ? $payloadValues : [], is_array($optionValues) ? $optionValues : []),
        ))));
    }

    /**
     * @return array<string, string>
     */
    private function parsePermissions(string $value): array
    {
        $permissions = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $part) {
            [$operation, $permission] = array_pad(explode(':', $part, 2), 2, '');
            if ($operation !== '') {
                $permissions[$operation] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function payloadPermissions(array $payload): array
    {
        $raw = $payload['permissions'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_filter($raw, static fn (mixed $value): bool => is_string($value));
    }
}
