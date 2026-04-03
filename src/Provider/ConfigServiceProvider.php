<?php

declare(strict_types=1);

namespace Kosmokrator\Provider;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Kosmokrator\ConfigLoader;

/**
 * Loads the layered config (bundled + user + project YAML files) and registers
 * the config repository. Also sets up Codex OAuth configuration keys.
 */
class ConfigServiceProvider extends ServiceProvider
{
    public function __construct(
        Container $container,
        private readonly string $basePath,
    ) {
        parent::__construct($container);
    }

    public function register(): void
    {
        $loader = new ConfigLoader($this->basePath.'/config');
        $config = $loader->load();

        $codexUrl = (string) $config->get('prism.providers.codex.url', 'https://chatgpt.com/backend-api/codex');
        $codexOAuthPort = (int) $config->get('kosmokrator.codex.oauth_port', 9876);

        $this->container->instance('config', $config);
        $this->container->alias('config', Repository::class);

        // Map prism.yaml keys to where Prism expects them
        $config->set('prism', $config->get('prism', []));
        $config->set('codex', [
            'url' => $codexUrl,
            'oauth_port' => $codexOAuthPort,
            'callback_route' => '/auth/codex/callback',
            'table' => 'codex_tokens',
            'id_token_add_organizations' => true,
            'originator' => 'kosmokrator',
            'user_agent' => 'kosmokrator',
        ]);
    }
}
