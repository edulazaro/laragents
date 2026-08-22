<?php

namespace EduLazaro\Laragents\Exceptions;

use Exception;

/** The provider answered 429. Their side, temporary: back off and retry. */
class ProviderRateLimited extends Exception
{
    public function __construct(string $provider = 'provider')
    {
        parent::__construct("{$provider} rate limit exceeded (HTTP 429).");
    }
}
