<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;
use Kosmokrator\Web\WebSearchRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:search', description: 'Run an external web search provider')]
final class WebSearchCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Search query')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider override')
            ->addOption('max-results', null, InputOption::VALUE_REQUIRED, 'Maximum results')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Mode/depth hint')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        \assert($config instanceof Repository);
        $registry = $this->container->make(WebProviderRegistry::class);

        try {
            $provider = $registry->searchProvider(is_string($input->getOption('provider')) ? $input->getOption('provider') : null);
            if (! $registry->enabled($provider->name())) {
                throw new \RuntimeException("Web provider '{$provider->name()}' is disabled.");
            }

            $response = $provider->search(new WebSearchRequest(
                query: (string) $input->getArgument('query'),
                maxResults: max(1, (int) ($input->getOption('max-results') ?: $config->get('kosmo.web.search.max_results', 8))),
                timeoutSeconds: max(1, (int) $config->get('kosmo.web.search.timeout_seconds', 30)),
                outputLimitChars: max(1000, (int) $config->get('kosmo.web.search.output_limit_chars', 60000)),
                mode: is_string($input->getOption('mode')) ? $input->getOption('mode') : null,
            ));
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        $input->getOption('json') ? $this->writeJson($output, ['success' => true, 'search' => $response->toArray()]) : $output->writeln(WebFormatter::search($response));

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}
