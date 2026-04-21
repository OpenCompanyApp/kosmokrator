<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Contracts;

use Kosmokrator\Web\Value\WebSearchRequest;
use Kosmokrator\Web\Value\WebSearchResponse;

interface WebSearchProvider
{
    public function id(): string;

    public function isAvailable(): bool;

    public function search(WebSearchRequest $request): WebSearchResponse;
}
