<?php

namespace Kosmokrator\LLM;

use Generator;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Tool;

class PrismService
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $systemPrompt,
        private readonly ?int $maxTokens = null,
        private readonly int|float|null $temperature = null,
    ) {}

    /**
     * @param Message[] $messages
     * @param Tool[] $tools
     * @return Generator<StreamEvent>
     */
    public function stream(array $messages, array $tools = []): Generator
    {
        $request = (new Prism)->text()
            ->using($this->provider, $this->model)
            ->withSystemPrompt($this->systemPrompt)
            ->withMessages($messages);

        if ($this->maxTokens !== null) {
            $request->withMaxTokens($this->maxTokens);
        }

        if ($this->temperature !== null) {
            $request->usingTemperature($this->temperature);
        }

        if (! empty($tools)) {
            $request->withTools($tools);
            $request->withMaxSteps(1); // We handle the tool loop ourselves
        }

        return $request->asStream();
    }
}
