<?php

declare(strict_types=1);

namespace Kosmokrator\Command\Mcp;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mcp:examples', description: 'Show MCP headless usage examples')]
final class McpExamplesCommand extends Command
{
    use InteractsWithMcpOutput;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $examples = [
            'add_project' => 'kosmo mcp:add github --project --type=stdio --command=github-mcp-server --env GITHUB_TOKEN --enable --json',
            'secret_stdin' => 'printf "$GITHUB_TOKEN" | kosmo mcp:secret:set github env.GITHUB_TOKEN --stdin --json',
            'list' => 'kosmo mcp:list --json',
            'tools' => 'kosmo mcp:tools github --json',
            'schema' => 'kosmo mcp:schema github.search_repositories --json',
            'call' => 'kosmo mcp:call github.search_repositories --query="kosmokrator" --json',
            'dynamic_call' => 'kosmo mcp:github search_repositories --query="kosmokrator" --json',
            'lua' => 'kosmo mcp:lua --eval \'dump(app.mcp.github.search_repositories({query="kosmokrator"}))\' --json',
        ];

        if ($input->getOption('json')) {
            $this->writeJson($output, ['success' => true, 'examples' => $examples]);
        } else {
            foreach ($examples as $example) {
                $output->writeln($example);
            }
        }

        return Command::SUCCESS;
    }
}
