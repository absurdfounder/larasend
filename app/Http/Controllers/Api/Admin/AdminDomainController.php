<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RecheckPendingDomains;
use App\Models\Project;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\DnsRecordVerifier;
use App\Services\Providers\DomainOnboardingException;
use App\Services\Providers\EmailProviderFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Custom-domain management for org projects (thin wrapper over the flows the
 * dashboard uses), plus the inbound-worker bundle Trooper needs to provision
 * a customer's own Cloudflare zone.
 *
 *   POST /api/admin/projects/{slug}/domains            { domain }
 *   GET  /api/admin/projects/{slug}/domains/{domain}
 *   GET  /api/admin/inbound/worker
 */
class AdminDomainController extends Controller
{
    public function __construct(
        private EmailProviderFactory $providers,
        private DnsRecordVerifier $dnsVerifier,
    ) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $source = $project->sources()->where('environment', 'prod')->first();
        if (! $source) {
            return response()->json(['message' => 'Project has no production source.'], 422);
        }

        $domainName = Str::lower(trim($validated['domain']));
        $claimedByAnotherProject = Project::query()
            ->whereKeyNot($project->id)
            ->whereHas('domains', fn ($query) => $query->where('domain', $domainName))
            ->exists();
        $platformDomain = Str::lower((string) config('larasend.platform.mail_domain'));

        if ($domainName !== $platformDomain && $claimedByAnotherProject) {
            return response()->json([
                'message' => 'This domain is already registered to another organization.',
                'code' => 'domain_conflict',
            ], 409);
        }

        $warning = null;

        try {
            $records = $this->providers->forSource($source)->dnsRecordsForDomain($source, $domainName);
        } catch (DomainOnboardingException $exception) {
            $records = $exception->fallbackRecords;
            $warning = $exception->getMessage();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $domain = $project->domains()->updateOrCreate(
            ['domain' => $domainName],
            [
                'status' => 'pending',
                'dns_records' => $records,
                'verified_at' => null,
            ],
        );

        RecheckPendingDomains::dispatch();

        return response()->json([
            'domain' => $domain->domain,
            'status' => $domain->status,
            'provider' => $source->provider->value,
            'dns_records' => $domain->dns_records,
            'warning' => $warning,
        ], 201);
    }

    public function show(Request $request, string $slug, string $domainName): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $domain = $project->domains()->where('domain', Str::lower(trim($domainName)))->first();
        if (! $domain) {
            return response()->json(['message' => 'Unknown domain.'], 404);
        }

        return response()->json([
            'domain' => $domain->domain,
            'status' => $domain->status,
            'provider' => $project->sources()->where('environment', 'prod')->first()?->provider?->value,
            'dns_records' => $domain->dns_records,
            'verified_at' => $domain->verified_at,
            'inbound_enabled_at' => $domain->inbound_enabled_at,
        ]);
    }

    public function verify(Request $request, string $slug, string $domainName): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $domain = $project->domains()->where('domain', Str::lower(trim($domainName)))->first();
        if (! $domain) {
            return response()->json(['message' => 'Unknown domain.'], 404);
        }

        $this->dnsVerifier->recheck($domain);
        $domain->refresh();

        return response()->json([
            'domain' => $domain->domain,
            'status' => $domain->status,
            'provider' => $project->sources()->where('environment', 'prod')->first()?->provider?->value,
            'dns_records' => $domain->dns_records,
            'verified_at' => $domain->verified_at,
            'inbound_enabled_at' => $domain->inbound_enabled_at,
        ]);
    }

    /**
     * Mark a custom domain inbound-enabled after Trooper has provisioned the
     * customer's Cloudflare zone (worker + catch-all + MX). The
     * InboundAddressRouter only matches domains with inbound_enabled_at set,
     * so this is the final step that turns the routing on.
     */
    public function enableInbound(Request $request, string $slug, string $domainName): JsonResponse
    {
        $project = $this->projectOr404($slug);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $domain = $project->domains()->where('domain', Str::lower(trim($domainName)))->first();
        if (! $domain) {
            return response()->json(['message' => 'Unknown domain.'], 404);
        }

        if (! in_array($domain->status, ['verified', 'local'], true)) {
            return response()->json([
                'message' => 'Domain DNS must be verified before inbound routing can be enabled.',
                'code' => 'domain_not_verified',
            ], 409);
        }

        $domain->forceFill(['inbound_enabled_at' => $domain->inbound_enabled_at ?? now()])->save();

        return response()->json([
            'domain' => $domain->domain,
            'status' => $domain->status,
            'inbound_enabled_at' => $domain->inbound_enabled_at,
        ]);
    }

    /**
     * The Cloudflare Email Routing worker bundle for the platform ROUTER
     * source. Trooper uses this to provision inbound on a customer-owned zone
     * with the customer's own Cloudflare token: upload the worker, enable
     * Email Routing, add a catch-all rule targeting it. Mail then flows into
     * the router webhook and the InboundAddressRouter delivers it to the
     * right org project.
     */
    public function worker(): JsonResponse
    {
        $router = $this->routerSource();
        if (! $router) {
            return response()->json(['message' => 'Platform router project is not set up.', 'code' => 'platform_not_ready'], 404);
        }

        $workerPath = resource_path('cloudflare/inbound-email-worker.js');
        $workerCode = is_file($workerPath) ? (string) file_get_contents($workerPath) : '';
        $inboundUrl = route('webhooks.inbound.cloudflare', ['token' => $router->webhook_token]);

        // The worker reads env.LARASEND_INBOUND_URL — the deployer must set
        // that binding (plain-text var) when uploading the worker script.
        return response()->json([
            'worker_name' => 'larasend-inbound-email',
            'worker_code' => $workerCode,
            'inbound_url' => $inboundUrl,
            'env' => ['LARASEND_INBOUND_URL' => $inboundUrl],
        ]);
    }

    private function projectOr404(string $slug): Project|JsonResponse
    {
        $workspace = $this->platformWorkspace();
        if (! $workspace) {
            return response()->json(['message' => 'Platform workspace is not set up.', 'code' => 'platform_not_ready'], 404);
        }

        $project = $workspace->projects()->where('slug', Str::lower($slug))->first();
        if (! $project) {
            return response()->json(['message' => 'Unknown organization project.'], 404);
        }

        return $project;
    }

    private function platformWorkspace(): ?Workspace
    {
        $slug = (string) config('larasend.platform.workspace_slug');

        return $slug === '' ? null : Workspace::query()->where('slug', $slug)->first();
    }

    private function routerSource(): ?Source
    {
        $workspace = $this->platformWorkspace();
        if (! $workspace) {
            return null;
        }
        $routerSlug = (string) config('larasend.platform.router_project_slug');
        $project = $workspace->projects()->where('slug', $routerSlug)->first();

        return $project?->sources()->where('environment', 'prod')->first();
    }
}
