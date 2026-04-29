<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Secrets;

use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Settings\SecretStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'secrets:set', description: 'Set a managed secret without echoing it')]
final class SecretsSetCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Secret key')
            ->addArgument('value', InputArgument::OPTIONAL, 'Secret value')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read secret value from stdin')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Read secret value from an environment variable')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');
        $value = $this->resolveSecretOption(
            inline: is_string($input->getArgument('value')) ? $input->getArgument('value') : null,
            stdin: (bool) $input->getOption('stdin'),
            env: is_string($input->getOption('env')) ? $input->getOption('env') : null,
        );

        if ($value === null || $value === '') {
            return $this->fail($input, $output, 'Secret value is required.');
        }

        try {
            $store = $this->container->make(SecretStore::class);
            $store->set($key, $value);
            $status = $store->status($key);
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'secret' => $status]);
        } else {
            $output->writeln('<info>Secret stored for '.$status['key'].'.</info>');
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

    private function resolveSecretOption(?string $inline, bool $stdin, ?string $env): ?string
    {
        if ($stdin) {
            $value = trim((string) stream_get_contents(STDIN));

            return $value === '' ? null : $value;
        }

        if ($env !== null && $env !== '') {
            $value = getenv($env);

            return is_string($value) && $value !== '' ? $value : null;
        }

        return $inline;
    }
}
