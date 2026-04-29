<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Web;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\Command\Concerns\InteractsWithHeadlessOutput;
use Kosmokrator\Web\WebProviderRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'web:doctor', description: 'Inspect optional web-provider configuration')]
final class WebDoctorCommand extends Command
{
    use InteractsWithHeadlessOutput;

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->container->make('config');
        \assert($config instanceof Repository);
        $registry = $this->container->make(WebProviderRegistry::class);
        $providers = $registry->statuses();
        $problems = [];

        foreach ($providers as $provider) {
            if ($provider['enabled'] && ! $provider['configured']) {
                $problems[] = "Provider {$provider['name']} is enabled but not configured.";
            }
        }

        $searchProvider = (string) $config->get('kosmokrator.web.search.provider', '');
        if ($this->bool($config->get('kosmokrator.web.search.enabled')) && $searchProvider === '') {
            $problems[] = 'web.search.enabled is on but web.search.provider is empty.';
        }
        $crawlProvider = (string) $config->get('kosmokrator.web.crawl.provider', '');
        if ($this->bool($config->get('kosmokrator.web.crawl.enabled')) && $crawlProvider === '') {
            $problems[] = 'web.crawl.enabled is on but web.crawl.provider is empty.';
        }

        $payload = [
            'success' => $problems === [],
            'search' => ['enabled' => $this->bool($config->get('kosmokrator.web.search.enabled')), 'provider' => $searchProvider ?: null],
            'fetch' => ['external_allowed' => $this->bool($config->get('kosmokrator.web.fetch.allow_external')), 'provider' => $config->get('kosmokrator.web.fetch.provider', 'native')],
            'crawl' => ['enabled' => $this->bool($config->get('kosmokrator.web.crawl.enabled')), 'provider' => $crawlProvider ?: null],
            'providers' => $providers,
            'problems' => $problems,
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, $payload);

            return $problems === [] ? Command::SUCCESS : Command::FAILURE;
        }

        (new Table($output))
            ->setHeaders(['Check', 'Value'])
            ->setRows([
                ['web_search', ($payload['search']['enabled'] ? 'enabled' : 'disabled').' / '.($payload['search']['provider'] ?? 'none')],
                ['external_fetch', ($payload['fetch']['external_allowed'] ? 'allowed' : 'blocked').' / '.$payload['fetch']['provider']],
                ['web_crawl', ($payload['crawl']['enabled'] ? 'enabled' : 'disabled').' / '.($payload['crawl']['provider'] ?? 'none')],
                ['providers', count(array_filter($providers, static fn (array $provider): bool => $provider['enabled'])).' enabled'],
            ])
            ->render();

        foreach ($problems as $problem) {
            $output->writeln("<error>{$problem}</error>");
        }

        return $problems === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
