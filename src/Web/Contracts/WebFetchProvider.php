<?php

declare(strict_types=1);

namespace Kosmokrator\Web\Contracts;

use Kosmokrator\Web\Value\WebFetchRequest;
use Kosmokrator\Web\Value\WebFetchResponse;

interface WebFetchProvider
{
    public function id(): string;

    public function isAvailable(): bool;

    public function fetch(WebFetchRequest $request): WebFetchResponse;
}
