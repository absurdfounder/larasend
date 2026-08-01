<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\SourceProvider;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\InboundAddress;
use App\Models\Project;
use App\Models\Source;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Idempotent create-or-get provisioning for a Trooper organization.
 *
 * POST /api/admin/orgs
 *   { org_id, org_slug, name, webhook_url, webhook_events?,
 *     rotate_api_key?, rotate_webhook_secret? }
 *
 * Secrets (api_key plain text, webhook signing_secret) appear in the response
 * ONLY when they are minted or rotated in this call — larasend stores hashes.
 */
class AdminProvisionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org_id' => ['required', 'string', 'max:128'],
            'org_slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['required', 'url', 'max:2048'],
            'webhook_events' => ['sometimes', 'array'],
            'webhook_events.*' => ['string', 'in:delivery,bounce,complaint,open,click,suppress,inbound.received'],
            'rotate_api_key' => ['sometimes', 'boolean'],
            'rotate_webhook_secret' => ['sometimes', 'boolean'],
        ]);

        $workspace = $this->platformWorkspace();
        if (! $workspace) {
            return response()->json([
                'message' => 'Platform workspace is not set up. See docs/larasend-integration/SPEC.md §Setup.',
                'code' => 'platform_not_ready',
            ], 404);
        }

        $slug = Str::lower($validated['org_slug']);
        $orgId = $validated['org_id'];

        /** @var Project|null $project */
        $project = $workspace->projects()->where('slug', $slug)->first();
        if ($project && $project->trooper_org_id !== null && $project->trooper_org_id !== $orgId) {
            // Slug collision with a different org — Trooper retries suffixed.
            return response()->json([
                'message' => 'Slug is already claimed by another organization.',
                'code' => 'slug_conflict',
            ], 409);
        }

        // An org keeps exactly one project even if its slug derivation changes.
        $existingByOrg = $workspace->projects()->where('trooper_org_id', $orgId)->first();
        if ($existingByOrg && (! $project || $existingByOrg->id !== $project->id)) {
            $project = $existingByOrg;
            $slug = $project->slug;
        }

        $created = false;
        if (! $project) {
            $project = $workspace->projects()->create([
                'name' => $validated['name'],
                'slug' => $slug,
                'default_environment' => 'prod',
            ]);
            $created = true;
        }
        if ($project->trooper_org_id !== $orgId) {
            $project->forceFill(['trooper_org_id' => $orgId])->save();
        }

        $source = $project->sources()->where('environment', 'prod')->first();
        if (! $source) {
            $source = $project->sources()->create([
                'name' => 'Production',
                'environment' => 'prod',
                'ses_region' => (string) config('larasend.platform.ses_region', 'us-east-1'),
                'default_from_name' => $validated['name'],
                'default_from_email' => null,
                'webhook_token' => Str::uuid()->toString(),
            ]);
        }

        // Platform provider credentials — every org project sends through the
        // platform's Cloudflare account or SES identity.
        $provider = (string) config('larasend.platform.provider', 'cloudflare');
        $source->forceFill(array_filter([
            'provider' => $provider === 'ses' ? SourceProvider::Ses : SourceProvider::Cloudflare,
            'cloudflare_api_token' => config('larasend.platform.cloudflare_api_token'),
            'cloudflare_account_id' => config('larasend.platform.cloudflare_account_id'),
            'aws_access_key_id' => config('larasend.platform.aws_access_key_id'),
            'aws_secret_access_key' => config('larasend.platform.aws_secret_access_key'),
            'ses_region' => config('larasend.platform.ses_region'),
            'ses_configuration_set' => config('larasend.platform.ses_configuration_set'),
        ], fn ($value) => $value !== null && $value !== ''))->save();

        // The shared platform domain is verified by fiat: it was verified once
        // when the platform zone was onboarded; per-org DNS re-checks are
        // meaningless for a shared zone, and the send gate requires a verified
        // Domain row on the project.
        $platformDomain = Str::lower((string) config('larasend.platform.mail_domain'));
        $domain = null;
        if ($platformDomain !== '') {
            $domain = $project->domains()->updateOrCreate(
                ['domain' => $platformDomain],
                [
                    'status' => 'verified',
                    'verified_at' => now(),
                    'inbound_enabled_at' => now(),
                ],
            );
        }

        $response = [
            'project' => ['id' => $project->id, 'slug' => $project->slug, 'created' => $created],
            'domain' => $domain ? ['domain' => $domain->domain, 'status' => $domain->status] : null,
        ];

        $rotateKey = (bool) ($validated['rotate_api_key'] ?? false);
        $activeKey = $project->apiKeys()->whereNull('revoked_at')->where('name', 'trooper-central')->first();
        if (! $activeKey || $rotateKey) {
            if ($activeKey && $rotateKey) {
                $activeKey->forceFill(['revoked_at' => now()])->save();
            }
            $issued = ApiKey::issue($project, 'trooper-central', $source, ['send', 'read:activity', 'manage:suppressions']);
            $response['api_key'] = $issued['plain_text'];
        }

        $events = $validated['webhook_events'] ?? ['inbound.received', 'delivery', 'bounce', 'complaint'];
        $rotateSecret = (bool) ($validated['rotate_webhook_secret'] ?? false);
        $endpoint = $project->webhookEndpoints()->where('url', $validated['webhook_url'])->first();
        if (! $endpoint || $rotateSecret) {
            if ($endpoint && $rotateSecret) {
                $endpoint->delete();
            }
            $issued = WebhookEndpoint::issue($project, $validated['webhook_url'], $events);
            $response['webhook'] = ['id' => $issued['endpoint']->public_id, 'signing_secret' => $issued['plain_text']];
        } else {
            $endpoint->forceFill(['events' => $events, 'status' => 'active'])->save();
            $response['webhook'] = ['id' => $endpoint->public_id];
        }

        $router = $this->routerSource();
        $response['router'] = $router
            ? ['inbound_url' => route('webhooks.inbound.cloudflare', ['token' => $router->webhook_token])]
            : null;

        return response()->json($response, $created ? 201 : 200);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $workspace = $this->platformWorkspace();
        if (! $workspace) {
            return response()->json(['message' => 'Platform workspace is not set up.', 'code' => 'platform_not_ready'], 404);
        }

        $project = $workspace->projects()->where('slug', Str::lower($slug))->first();
        if (! $project) {
            return response()->json(['message' => 'Unknown organization project.'], 404);
        }

        return response()->json([
            'project' => ['id' => $project->id, 'slug' => $project->slug, 'trooper_org_id' => $project->trooper_org_id],
            'domains' => $project->domains()->get(['domain', 'status', 'verified_at', 'inbound_enabled_at']),
            'api_key_active' => $project->apiKeys()->whereNull('revoked_at')->where('name', 'trooper-central')->exists(),
            'webhooks' => $project->webhookEndpoints()->get(['public_id', 'url', 'events', 'status']),
            'inbound_addresses' => InboundAddress::query()->where('project_id', $project->id)->count(),
        ]);
    }

    public function health(): JsonResponse
    {
        $workspace = $this->platformWorkspace();
        $router = $this->routerSource();
        $platformDomain = (string) config('larasend.platform.mail_domain');

        return response()->json([
            'ok' => (bool) ($workspace && $router && $platformDomain !== ''),
            'workspace' => $workspace?->slug,
            'router_project' => $router?->project?->slug,
            'router_inbound_url' => $router
                ? route('webhooks.inbound.cloudflare', ['token' => $router->webhook_token])
                : null,
            'router_ses_webhook_url' => $router
                ? route('webhooks.ses', ['token' => $router->webhook_token])
                : null,
            'platform_domain' => $platformDomain ?: null,
            'provider' => (string) config('larasend.platform.provider', 'cloudflare'),
        ]);
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
