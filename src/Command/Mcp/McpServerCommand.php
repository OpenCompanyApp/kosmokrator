<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Illuminate\Container\Container;
use Kosmokrator\Integration\Runtime\IntegrationArgumentMapper;
use Kosmokrator\Mcp\McpConfigStore;
use Kosmokrator\Mcp\McpRuntime;
use Kosmokrator\Settings\SettingsManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class McpServerCommand extends Command
{
    use InteractsWithMcpOutput;

    public function __construct(private readonly Container $container, private readonly string $server)
    {
        parent::__construct("mcp:{$server}");
        $this->setDescription("Call {$server} MCP functions");
    }

    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this
            ->addArgument('function', InputArgument::REQUIRED, 'Function name')
            ->addArgument('payload', InputArgument::OPTIONAL, 'JSON object payload')
            ->addArgument('extra', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Additional loose args')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass MCP trust and read/write permission policy')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->container->make(McpConfigStore::class);
        $this->configureProjectRoot($store, $this->container->make(SettingsManager::class));
        $function = (string) $input->getArgument('function');
        $name = "{$this->server}.{$function}";
        $payload = is_string($input->getArgument('payload')) ? $input->getArgument('payload') : $this->readStdinIfPiped();
        $tokens = $this->tokensAfterFunction($this->rawTokens($input), $function, $payload);
        $json = (bool) $input->getOption('json') || $this->rawFlag($this->rawTokens($input), 'json');
        $force = (bool) $input->getOption('force') || $this->rawFlag($this->rawTokens($input), 'force');
        $dryRun = (bool) $input->getOption('dry-run') || $this->rawFlag($this->rawTokens($input), 'dry-run');

        try {
            $args = $this->container->make(IntegrationArgumentMapper::class)->map($tokens, $payload);
            $result = $this->container->make(McpRuntime::class)->call($name, $args, $force, $dryRun);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage(), 'function' => $name];
        }

        if ($json) {
            $this->writeJson($output, $result);
        } elseif (! ($result['success'] ?? false)) {
            $output->writeln('<error>'.($result['error'] ?? 'MCP call failed.').'</error>');
        } else {
            $output->writeln(is_string($result['data']) ? $result['data'] : $this->jsonEncode($result['data']));
        }

        return ($result['success'] ?? false) ? Command::SUCCESS : Command::FAILURE;
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
