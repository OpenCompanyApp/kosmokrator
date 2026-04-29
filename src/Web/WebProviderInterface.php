<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

interface WebProviderInterface
{
    public function name(): string;

    public function label(): string;

    public function supports(WebCapability $capability): bool;

    public function search(WebSearchRequest $request): WebSearchResponse;

    public function fetch(WebFetchRequest $request): WebFetchResponse;

    public function crawl(WebCrawlRequest $request): WebCrawlResponse;
}
