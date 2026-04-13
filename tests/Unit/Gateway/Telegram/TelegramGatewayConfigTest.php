<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit\Gateway\Telegram;

use Illuminate\Config\Repository;
use Kosmokrator\Gateway\Telegram\TelegramGatewayConfig;
use Kosmokrator\Settings\SettingsManager;
use Kosmokrator\Settings\SettingsSchema;
use Kosmokrator\Settings\YamlConfigStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TelegramGatewayConfigTest extends TestCase
{
    public function test_reads_session_mode_and_lists_from_settings_manager(): void
    {
        $config = new Repository([
            'kosmokrator' => [
                'gateway' => [
                    'telegram' => [
                        'enabled' => false,
                        'token' => null,
                        'session_mode' => 'thread',
                    ],
                ],
            ],
        ]);
        $manager = new SettingsManager($config, new SettingsSchema, new YamlConfigStore(new NullLogger), __DIR__.'/../../../../config');
        $manager->setProjectRoot(null);
        $manager->setRaw('kosmokrator.gateway.telegram.enabled', true, 'global');
        $manager->setRaw('kosmokrator.gateway.telegram.token', 'abc123', 'global');
        $manager->setRaw('kosmokrator.gateway.telegram.session_mode', 'chat_user', 'global');
        $manager->setRaw('kosmokrator.gateway.telegram.allowed_users', '1,alice', 'global');

        $gateway = TelegramGatewayConfig::fromSettings($manager, $config);

        $this->assertTrue($gateway->enabled);
        $this->assertSame('abc123', $gateway->token);
        $this->assertSame('chat_user', $gateway->sessionMode);
        $this->assertSame(['1', 'alice'], $gateway->allowedUsers);
    }
}
