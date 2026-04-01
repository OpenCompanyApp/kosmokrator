<?php

declare(strict_types=1);

namespace Kosmokrator\LLM;

final readonly class ProviderDefinition
{
    /**
     * @param  list<ModelDefinition>  $models
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $authMode,
        public string $url,
        public string $defaultModel,
        public array $models,
    ) {}
}
