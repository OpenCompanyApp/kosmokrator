<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\SettingValueFormatter;
use Kosmokrator\Settings\SettingValueParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:set', description: 'Set one setting headlessly')]
final class SettingsSetCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Setting key')
            ->addArgument('value', InputArgument::REQUIRED, 'Setting value')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider context for dynamic model validation')
            ->addOption('allow-unlisted-model', null, InputOption::VALUE_NONE, 'Allow an unadvertised model value when setting agent.default_model')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $catalog = $this->container->make(SettingsCatalog::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

        $key = (string) $input->getArgument('key');
        $definition = $schema->definition($key);
        if ($definition === null) {
            return $this->fail($input, $output, "Unknown setting [{$key}].");
        }

        try {
            $value = (new SettingValueParser)->parse($definition, $input->getArgument('value'));
            $provider = is_string($input->getOption('provider')) ? $input->getOption('provider') : null;
            $this->validateChoice($definition->id, $value, $catalog, $provider, (bool) $input->getOption('allow-unlisted-model'));
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        $scope = $this->scope($input);
        if (! $input->getOption('dry-run')) {
            $settings->set($definition->id, $value, $scope);
        }

        $payload = [
            'success' => true,
            'dry_run' => (bool) $input->getOption('dry-run'),
            'scope' => $scope,
            'key' => $definition->id,
            'written_value' => $value,
            'written_display_value' => SettingValueFormatter::display($value),
            'effective_setting' => $catalog->setting($definition->id),
            'setting' => $catalog->setting($definition->id),
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $payload);
        } else {
            $output->writeln('<info>Saved '.$definition->id.' to '.$scope.' config.</info>');
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

    private function validateChoice(string $id, mixed $value, SettingsCatalog $catalog, ?string $provider = null, bool $allowUnlistedModel = false): void
    {
        if ($allowUnlistedModel && $id === 'agent.default_model') {
            return;
        }

        $options = $catalog->options($id, $provider === null ? [] : ['provider' => $provider]);
        $values = array_values(array_filter(
            array_map(static fn (array $option): string => (string) $option['value'], $options),
            static fn (string $option): bool => $option !== '*' && $option !== '',
        ));

        if ($values !== [] && is_scalar($value) && ! in_array((string) $value, $values, true)) {
            throw new \InvalidArgumentException("Invalid value [{$value}] for [{$id}]. Allowed: ".implode(', ', $values));
        }
    }
}
