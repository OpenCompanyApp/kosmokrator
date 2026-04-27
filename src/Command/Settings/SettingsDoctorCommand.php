<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\LLM\ProviderCatalog;
use Kosmokrator\Settings\SettingsCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:doctor', description: 'Diagnose headless configuration state')]
final class SettingsDoctorCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalog = $this->container->make(SettingsCatalog::class);
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $providerCatalog = $this->container->make(ProviderCatalog::class);
        $settings = $catalog->settings();
        $byId = [];
        foreach ($settings as $setting) {
            $byId[$setting['id']] = $setting;
        }

        $provider = (string) ($byId['agent.default_provider']['value'] ?? '');
        $model = (string) ($byId['agent.default_model']['value'] ?? '');
        $providerDefinition = $provider !== '' ? $providerCatalog->provider($provider) : null;
        $issues = [];
        $next = [
            'kosmokrator settings:list --json',
            'kosmokrator providers:list --json',
            'kosmokrator secrets:list --json',
        ];

        if ($providerDefinition === null) {
            $issues[] = "Default provider [{$provider}] is not available.";
            $next[] = 'kosmokrator providers:list --json';
        } elseif ($providerDefinition->authMode === 'api_key' && trim($providerCatalog->apiKey($provider)) === '') {
            $issues[] = "Default provider [{$provider}] is missing an API key.";
            $next[] = "kosmokrator providers:configure {$provider} --api-key ... --json";
        }

        if ($providerDefinition !== null && $model !== '' && ! $providerCatalog->supportsModel($provider, $model)) {
            $issues[] = "Default model [{$model}] is not advertised by provider [{$provider}].";
            $next[] = "kosmokrator providers:models {$provider} --json";
        }

        $payload = [
            'success' => $issues === [],
            'paths' => $catalog->paths(),
            'default_provider' => $provider,
            'default_model' => $model,
            'provider_auth_status' => $provider !== '' ? $providerCatalog->authStatus($provider) : null,
            'issues' => $issues,
            'next_commands' => array_values(array_unique($next)),
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $payload);
        } else {
            foreach ($issues === [] ? ['Settings look usable for headless runs.'] : $issues as $line) {
                $output->writeln($line);
            }
        }

        return $issues === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
