<?php

declare(strict_types=1);

namespace Kosmokrator\Web;

enum WebCapability: string
{
    case Search = 'search';
    case Fetch = 'fetch';
    case Crawl = 'crawl';
}
