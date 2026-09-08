<?php

namespace App\Services\Providers;

use App\Models\Source;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class CloudflareSmtpTransportFactory
{
    /**
     * Cloudflare authenticated SMTP. Port 465 (implicit TLS) hangs from
     * Railway/Hetzner and SIGKILLs the queue worker; 587 STARTTLS answers.
     */
    public function create(Source $source): TransportInterface
    {
        $transport = new EsmtpTransport('smtp.mx.cloudflare.net', 587, tls: false);
        $transport->setUsername('api_token');
        $transport->setPassword((string) $source->cloudflare_api_token);
        $stream = $transport->getStream();
        $stream->setTimeout(12);

        return $transport;
    }
}
