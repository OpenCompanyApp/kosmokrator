<?php

namespace Kosmokrator\Tests\Unit\LLM;

use Kosmokrator\LLM\PrismService;
use PHPUnit\Framework\TestCase;

class PrismServiceTest extends TestCase
{
    public function test_get_provider(): void
    {
        $service = new PrismService('anthropic', 'claude-4', 'prompt');

        $this->assertSame('anthropic', $service->getProvider());
    }

    public function test_get_model(): void
    {
        $service = new PrismService('anthropic', 'claude-4-sonnet', 'prompt');

        $this->assertSame('claude-4-sonnet', $service->getModel());
    }

    public function test_supports_streaming_for_z_provider(): void
    {
        $service = new PrismService('z', 'GLM-5.1', 'prompt');

        $this->assertFalse($service->supportsStreaming());
    }

    public function test_supports_streaming_for_anthropic(): void
    {
        $service = new PrismService('anthropic', 'claude-4', 'prompt');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_supports_streaming_for_openai(): void
    {
        $service = new PrismService('openai', 'gpt-4', 'prompt');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_supports_streaming_for_arbitrary_provider(): void
    {
        $service = new PrismService('custom_provider', 'model', 'prompt');

        $this->assertTrue($service->supportsStreaming());
    }

    public function test_provider_switch_updates_temperature_support(): void
    {
        $service = new PrismService('z', 'glm-5.1', 'prompt', temperature: 0.7);
        $supportsTemperature = new \ReflectionMethod($service, 'supportsTemperature');

        $this->assertFalse($supportsTemperature->invoke($service));

        $service->setProvider('openai');

        $this->assertTrue($supportsTemperature->invoke($service));
    }
}
