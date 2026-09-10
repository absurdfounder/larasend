<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class CloudflareEmailSendingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $providerContext
     */
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly array $providerContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
