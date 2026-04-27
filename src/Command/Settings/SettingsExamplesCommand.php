<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Settings;

use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'settings:examples', description: 'Show headless settings examples')]
final class SettingsExamplesCommand extends Command
{
    use InteractsWithHeadlessOutput;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $examples = [
            ['description' => 'List all settings', 'command' => 'kosmokrator settings:list --json'],
            ['description' => 'Set default model globally with provider context', 'command' => 'kosmokrator settings:set agent.default_model gpt-5.4-mini --provider openai --global --json'],
            ['description' => 'Batch apply provider and model together', 'command' => 'jq -n \'{scope:"global",settings:{"agent.default_provider":"openai","agent.default_model":"gpt-5.4-mini"}}\' | kosmokrator settings:apply --stdin-json --json'],
            ['description' => 'Configure provider with API key from stdin', 'command' => 'printf %s "$OPENAI_API_KEY" | kosmokrator providers:configure openai --model gpt-5.4-mini --api-key-stdin --global --json'],
            ['description' => 'Configure provider with API key from env', 'command' => 'kosmokrator providers:configure openai --model gpt-5.4-mini --api-key-env OPENAI_API_KEY --global --json'],
            ['description' => 'Set a secret via stdin', 'command' => 'printf %s "$OPENAI_API_KEY" | kosmokrator secrets:set provider.openai.api_key --stdin --json'],
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, ['examples' => $examples]);
        } else {
            foreach ($examples as $example) {
                $output->writeln($example['description'].': '.$example['command']);
            }
        }

        return Command::SUCCESS;
    }
}
