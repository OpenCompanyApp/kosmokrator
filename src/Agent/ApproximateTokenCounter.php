<?php

declare(strict_types=1);

namespace Kosmokrator\Agent;

use Kosmokrator\LLM\ToolCallMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Fast cached token estimator for pre-flight decisions and diagnostics.
 */
final class ApproximateTokenCounter implements TokenCounterInterface
{
    private const CHARS_PER_TOKEN = 3.2;

    private const MESSAGE_OVERHEAD_TOKENS = 10;

    private const TOOL_SCHEMA_OVERHEAD_TOKENS = 18;

    private const CACHE_LIMIT = 4096;

    /** @var array<string, int> */
    private array $cache = [];

    /** @var list<string> */
    private array $order = [];

    public function countText(string $text, string $bucket = 'text'): int
    {
        if ($text === '') {
            return 0;
        }

        $key = $bucket.':'.strlen($text).':'.hash('xxh3', $text);
        if (isset($this->cache[$key])) {
            $this->touch($key);

            return $this->cache[$key];
        }

        return $this->remember($key, max(0, (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN)));
    }

    public function countMessage(Message $message): int
    {
        $contentTokens = match (true) {
            $message instanceof UserMessage => $this->countText($message->content, 'message:user'),
            $message instanceof AssistantMessage => $this->countText($message->content, 'message:assistant')
                + $this->countAssistantToolCalls($message),
            $message instanceof ToolResultMessage => $this->countToolResults($message),
            $message instanceof SystemMessage => $this->countText($message->content, 'message:system'),
            default => 0,
        };

        return $contentTokens + self::MESSAGE_OVERHEAD_TOKENS;
    }

    public function countMessages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            $total += $this->countMessage($message);
        }

        return $total;
    }

    public function countTools(array $tools): int
    {
        $total = 0;
        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }

            $payload = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersAsArray(),
                'required' => $tool->requiredParameters(),
            ];
            $total += self::TOOL_SCHEMA_OVERHEAD_TOKENS
                + $this->countText(json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 'tool:schema');
        }

        return $total;
    }

    private function countAssistantToolCalls(AssistantMessage $message): int
    {
        $total = 0;
        foreach ($message->toolCalls as $toolCall) {
            $total += $this->countText($toolCall->name.json_encode(ToolCallMapper::safeArguments($toolCall), JSON_INVALID_UTF8_SUBSTITUTE), 'tool:call');
        }

        return $total;
    }

    private function countToolResults(ToolResultMessage $message): int
    {
        $total = 0;
        foreach ($message->toolResults as $toolResult) {
            $result = is_string($toolResult->result)
                ? $toolResult->result
                : json_encode($toolResult->result, JSON_INVALID_UTF8_SUBSTITUTE);
            $total += $this->countText((string) $result, 'tool:result');
        }

        return $total;
    }

    private function remember(string $key, int $tokens): int
    {
        $this->cache[$key] = $tokens;
        $this->touch($key);

        while (count($this->order) > self::CACHE_LIMIT) {
            $oldest = array_shift($this->order);
            if ($oldest !== null) {
                unset($this->cache[$oldest]);
            }
        }

        return $tokens;
    }

    private function touch(string $key): void
    {
        $index = array_search($key, $this->order, true);
        if ($index !== false) {
            array_splice($this->order, $index, 1);
        }

        $this->order[] = $key;
    }
}
