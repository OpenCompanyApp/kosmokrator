<?php

declare(strict_types=1);

namespace Kosmokrator\Tests\Unit;

use Illuminate\Container\Container;
use Kosmokrator\Kernel;
use Kosmokrator\Provider\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class KernelFailureCleanupTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function test_failed_boot_flushes_partial_container_state(): void
    {
        $state = new \stdClass;
        $kernel = new class(dirname(__DIR__, 2), $state) extends Kernel
        {
            public function __construct(string $basePath, private readonly \stdClass $state)
            {
                parent::__construct($basePath);
            }

            protected function serviceProviders(Container $container): array
            {
                $this->state->container = $container;

                return [
                    new class($container) extends ServiceProvider
                    {
                        public function register(): void
                        {
                            $this->container->instance('partial.boot.binding', 'registered');
                        }

                        public function boot(): void
                        {
                            throw new \RuntimeException('Synthetic provider boot failure.');
                        }
                    },
                ];
            }
        };

        try {
            $kernel->boot();
            $this->fail('Expected boot failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic provider boot failure.', $e->getMessage());
        }

        $this->assertInstanceOf(Container::class, $state->container);
        $this->assertFalse($state->container->bound('partial.boot.binding'));
        $this->assertNotSame($state->container, Container::getInstance());
    }
}
