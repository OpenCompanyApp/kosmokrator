<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Integration;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use Kosmokrator\Integration\Runtime\IntegrationDocService;
use Kosmokrator\Integration\Runtime\IntegrationRuntime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class IntegrationProviderCommand extends Command
{
    use InteractsWithIntegrationOutput;

    public function __construct(
        private readonly Container $container,
        private readonly string $provider,
    ) {
        parent::__construct("integrations:{$provider}");
        $this->setDescription("Call {$provider} integration functions");
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();

        $this
            ->addArgument('function', InputArgument::OPTIONAL, 'Function name')
            ->addArgument('payload', InputArgument::OPTIONAL, 'JSON object payload')
            ->addArgument('extra', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Additional loose args')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Account alias');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $function = $input->getArgument('function');
        if (! is_string($function) || $function === '') {
            $output->writeln($this->container->make(IntegrationDocService::class)->render($this->provider, $input->getOption('json') ? 'json' : 'text'));

            return Command::SUCCESS;
        }

        $name = "{$this->provider}.{$function}";
        $payload = is_string($input->getArgument('payload')) ? $input->getArgument('payload') : $this->readStdinIfPiped();
        $rawTokens = $this->rawTokens($input);
        $tokens = $this->tokensAfterFunction($rawTokens, $function, $payload);
        $json = (bool) $input->getOption('json') || $this->rawFlag($rawTokens, 'json');
        $account = is_string($input->getOption('account')) ? $input->getOption('account') : $this->rawOption($rawTokens, 'account');

        try {
            $args = $this->container->make(IntegrationArgumentMapper::class)->map($tokens, $payload);
            $result = $this->container->make(IntegrationRuntime::class)->call(
                name: $name,
                args: $args,
                account: $account,
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
            $output->writeln(is_string($result->data)
                ? $result->data
                : (json_encode($result->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
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
}
