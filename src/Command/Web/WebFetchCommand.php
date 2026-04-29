<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Web\WebFetchRequest;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:fetch', description: 'Fetch or extract a URL through an external provider')]
final class WebFetchCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL to fetch')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider override')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'markdown, text, or html')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        \assert($config instanceof Repository);
        $registry = $this->container->make(WebProviderRegistry::class);

        try {
            $provider = $registry->fetchProvider(is_string($input->getOption('provider')) ? $input->getOption('provider') : null);
            if (! $registry->enabled($provider->name())) {
                throw new \RuntimeException("Web provider '{$provider->name()}' is disabled.");
            }

            $response = $provider->fetch(new WebFetchRequest(
                url: (string) $input->getArgument('url'),
                format: is_string($input->getOption('format')) && $input->getOption('format') !== '' ? $input->getOption('format') : 'markdown',
                timeoutSeconds: max(1, (int) $config->get('kosmokrator.web.fetch.timeout_seconds', 30)),
                outputLimitChars: max(1000, (int) $config->get('kosmokrator.web.fetch.output_limit_chars', 100000)),
            ));
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        $input->getOption('json') ? $this->writeJson($output, ['success' => true, 'fetch' => $response->toArray()]) : $output->writeln(WebFormatter::fetch($response));

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}
