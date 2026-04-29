<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Kosmokrator\Integration\Runtime\IntegrationRuntimeOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integrations:call', description: 'Call an integration function')]
final class IntegrationCallCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();

        $this
            ->addArgument('function', InputArgument::REQUIRED, 'provider.function')
            ->addArgument('payload', InputArgument::OPTIONAL, 'JSON object payload')
            ->addArgument('extra', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Additional loose args')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account alias')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass integration read/write permission policy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate resolution, arguments, credentials, and permissions without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('function');
        $payload = is_string($input->getArgument('payload')) ? $input->getArgument('payload') : $this->readStdinIfPiped();
        $rawTokens = $this->rawTokens($input);
        $tokens = $this->tokensAfterFunction($rawTokens, $name, $payload);
        $json = (bool) $input->getOption('json') || $this->rawFlag($rawTokens, 'json');
        $account = is_string($input->getOption('account')) ? $input->getOption('account') : $this->rawOption($rawTokens, 'account');
        $force = (bool) $input->getOption('force') || $this->rawFlag($rawTokens, 'force');
        $dryRun = (bool) $input->getOption('dry-run') || $this->rawFlag($rawTokens, 'dry-run');

        try {
            $args = $this->container->make(IntegrationArgumentMapper::class)->map($tokens, $payload);
            $result = $this->container->make(IntegrationRuntime::class)->call(
                name: $name,
                args: $args,
                options: new IntegrationRuntimeOptions($account, $force, $dryRun),
            );
        } catch (\Throwable $e) {
            if ($json) {
                $this->writeJson($output, ['success' => false, 'error' => $e->getMessage()]);
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        if ($json) {
            $this->writeJson($output, $result->toArray());
        } elseif (! $result->success) {
            $output->writeln('<error>'.($result->error ?? 'Integration call failed.').'</error>');
        } else {
            $output->writeln($this->formatData($result->data));
        }

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function tokensAfterFunction(array $tokens, string $function, ?string $payload): array
    {
        if (($tokens[0] ?? '') === $this->getName()) {
            array_shift($tokens);
        }
        if (($tokens[0] ?? '') === $function) {
            array_shift($tokens);
        }
        if ($payload !== null && ($tokens[0] ?? null) === $payload) {
            array_shift($tokens);
        }

        return $tokens;
    }

    private function formatData(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
