<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final readonly class ProviderDefinition
{
    /**
     * @param  list<ModelDefinition>  $models
     * @param  list<string>  $inputModalities
     * @param  list<string>  $outputModalities
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $authMode,
        public string $source,
        public string $driver,
        public string $url,
        public string $defaultModel,
        public array $models,
        public array $inputModalities = ['text'],
        public array $outputModalities = ['text'],
    ) {}
}
