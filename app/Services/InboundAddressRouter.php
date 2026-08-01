<?php

namespace App\Services;

use App\Exceptions\UnknownInboundRecipient;
use App\Models\Domain;
use App\Models\InboundAddress;
use App\Models\Project;
use App\Models\Source;
use Illuminate\Support\Str;

/**
 * Resolves which project an inbound message belongs to.
 *
 * Resolution order:
 *  1. Exact address match in inbound_addresses (per-agent platform addresses).
 *  2. Shared platform domain with no exact match → reject. The platform Domain
 *     row is attached to EVERY org project, so falling through to the domain
 *     match would deliver into an arbitrary tenant — never allow that.
 *  3. Inbound-enabled Domain match on the recipient's domain (custom org
 *     domains — one project per domain).
 *  4. Anything else falls back to the receiving source's own project, which
 *     preserves stock single-tenant larasend behavior exactly.
 */
class InboundAddressRouter
{
    public function resolveProject(Source $source, string $envelopeTo): Project
    {
        $address = Str::lower(trim($envelopeTo));
        $atPos = strrpos($address, '@');
        $recipientDomain = $atPos === false ? '' : substr($address, $atPos + 1);

        $exact = InboundAddress::query()
            ->where('address', $address)
            ->with('project')
            ->first();

        if ($exact?->project) {
            return $exact->project;
        }

        $platformDomain = Str::lower((string) config('larasend.platform.mail_domain'));

        if ($platformDomain !== '' && $recipientDomain === $platformDomain) {
            throw new UnknownInboundRecipient($address);
        }

        if ($recipientDomain !== '') {
            $domain = Domain::query()
                ->where('domain', $recipientDomain)
                ->whereNotNull('inbound_enabled_at')
                ->with('project')
                ->orderByDesc('verified_at')
                ->first();

            if ($domain?->project) {
                return $domain->project;
            }
        }

        return $source->project;
    }
}
