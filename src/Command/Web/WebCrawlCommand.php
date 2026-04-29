<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Web\WebCrawlRequest;
use Kosmokrator\Web\WebFormatter;
use Kosmokrator\Web\WebProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:crawl', description: 'Crawl a site through an external provider')]
final class WebCrawlCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'Starting URL')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider override')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Maximum pages')
            ->addOption('instructions', null, InputOption::VALUE_REQUIRED, 'Provider-specific crawl instructions')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        \assert($config instanceof Repository);
        $registry = $this->container->make(WebProviderRegistry::class);

        try {
            $provider = $registry->crawlProvider(is_string($input->getOption('provider')) ? $input->getOption('provider') : null);
            if (! $registry->enabled($provider->name())) {
                throw new \RuntimeException("Web provider '{$provider->name()}' is disabled.");
            }

            $response = $provider->crawl(new WebCrawlRequest(
                url: (string) $input->getArgument('url'),
                maxPages: max(1, (int) ($input->getOption('max-pages') ?: $config->get('kosmokrator.web.crawl.max_pages', 20))),
                timeoutSeconds: max(1, (int) $config->get('kosmokrator.web.crawl.timeout_seconds', 60)),
                outputLimitChars: max(1000, (int) $config->get('kosmokrator.web.crawl.output_limit_chars', 100000)),
                instructions: is_string($input->getOption('instructions')) ? $input->getOption('instructions') : null,
            ));
        } catch (\Throwable $e) {
            return $this->fail($input, $output, $e->getMessage());
        }

        $input->getOption('json') ? $this->writeJson($output, ['success' => true, 'crawl' => $response->toArray()]) : $output->writeln(WebFormatter::crawl($response));

        return Command::SUCCESS;
    }

    private function fail(InputInterface $input, OutputInterface $output, string $message): int
    {
        $input->getOption('json') ? $this->writeJson($output, ['success' => false, 'error' => $message]) : $output->writeln("<error>{$message}</error>");

        return Command::FAILURE;
    }
}
