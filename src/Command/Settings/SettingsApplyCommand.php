<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Illuminate\Container\Container;
use Kosmokrator\Agent\InstructionLoader;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SettingsCatalog;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\SettingValueParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:apply', description: 'Apply settings from stdin JSON')]
final class SettingsApplyCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stdin-json', null, InputOption::VALUE_NONE, 'Read settings payload from stdin')
            ->addOption('global', null, InputOption::VALUE_NONE, 'Write global config')
            ->addOption('project', null, InputOption::VALUE_NONE, 'Write project config')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without writing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (! $input->getOption('stdin-json')) {
            return $this->fail($output, 'Pass --stdin-json with a JSON payload.');
        }

        $settings = $this->container->make(SettingsManager::class);
        $schema = $this->container->make(SettingsSchema::class);
        $catalog = $this->container->make(SettingsCatalog::class);
        $settings->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());
        $catalog->setProjectRoot(InstructionLoader::gitRoot() ?? getcwd());

        try {
            $payload = $this->stdinJson();
        } catch (\Throwable $e) {
            return $this->fail($output, $e->getMessage());
        }

        $rawSettings = $payload['settings'] ?? $payload['set'] ?? [];
        $allowUnlistedModel = (bool) ($payload['allow_unlisted_model'] ?? $payload['allow_unlisted_models'] ?? false);
        if (! is_array($rawSettings)) {
            return $this->fail($output, 'Payload must contain a settings object.');
        }

        $scope = $this->scope($input, is_string($payload['scope'] ?? null) ? $payload['scope'] : null);
        $parser = new SettingValueParser;
        $updates = [];
        $errors = [];
        $parsedValues = [];

        foreach ($rawSettings as $key => $rawValue) {
            $definition = $schema->definition((string) $key);
            if ($definition === null) {
                $errors[] = ['key' => (string) $key, 'error' => 'Unknown setting.'];

                continue;
            }

            try {
                $value = $parser->parse($definition, $rawValue);
                $parsedValues[$definition->id] = $value;
                $updates[] = ['key' => $definition->id, 'value' => $value];
            } catch (\Throwable $e) {
                $errors[] = ['key' => $definition->id, 'error' => $e->getMessage()];
            }
        }

        foreach ($parsedValues as $id => $value) {
            try {
                $this->validateChoice($id, $value, $catalog, $parsedValues, $allowUnlistedModel);
            } catch (\Throwable $e) {
                $errors[] = ['key' => $id, 'error' => $e->getMessage()];
            }
        }

        if ($errors === [] && ! $input->getOption('dry-run')) {
            foreach ($parsedValues as $id => $value) {
                $settings->set($id, $value, $scope);
            }
        }

        $result = [
            'success' => $errors === [],
            'dry_run' => (bool) $input->getOption('dry-run'),
            'scope' => $scope,
            'updated' => $updates,
            'effective_settings' => $errors === [] && ! $input->getOption('dry-run')
                ? array_values(array_filter(array_map(
                    fn (string $id): ?array => $catalog->setting($id),
                    array_keys($parsedValues),
                )))
                : [],
            'errors' => $errors,
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $result);
        } else {
            $output->writeln(($errors === [] ? '<info>' : '<error>').count($updates).' settings processed.'.($errors === [] ? '</info>' : '</error>'));
        }

        return $errors === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $this->writeJson($output, ['success' => false, 'error' => $message]);

        return Command::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function validateChoice(string $id, mixed $value, SettingsCatalog $catalog, array $pending, bool $allowUnlistedModel = false): void
    {
        if ($allowUnlistedModel && $id === 'agent.default_model') {
            return;
        }

        $provider = is_string($pending['agent.default_provider'] ?? null) ? $pending['agent.default_provider'] : null;
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
