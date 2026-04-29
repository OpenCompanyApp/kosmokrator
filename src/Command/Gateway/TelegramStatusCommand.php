<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Gateway;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SecretStore;
use Kosmokrator\Settings\SettingsCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'gateway:telegram:status', description: 'Show Telegram gateway configuration status')]
final class TelegramStatusCommand extends Command
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
        $settings = array_values(array_filter(
            $catalog->settings('gateway'),
            static fn (array $setting): bool => str_starts_with((string) $setting['id'], 'gateway.telegram.'),
        ));
        $token = $this->container->make(SecretStore::class)->status('gateway.telegram.token');

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'settings' => $settings, 'token' => $token]);

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Key', 'Value', 'Source'])
            ->setRows(array_map(static fn (array $setting): array => [
                $setting['id'],
                $setting['display_value'],
                $setting['source'],
            ], $settings))
            ->render();
        $output->writeln('gateway.telegram.token: '.($token['configured'] ? 'configured' : 'missing'));

        return Command::SUCCESS;
    }
}
