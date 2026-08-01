<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when mail arrives on the shared platform domain for an address no
 * project has registered. The Cloudflare worker turns the resulting 422 into
 * a permanent SMTP reject so the sender gets a bounce instead of the message
 * leaking into another tenant's project.
 */
class UnknownInboundRecipient extends RuntimeException
{
    public function __construct(public readonly string $address)
    {
        parent::__construct("No project accepts mail for {$address}.");
    }
}
