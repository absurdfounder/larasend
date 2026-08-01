<?php

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function platformFixture(): Workspace
{
    config()->set('larasend.admin_token', 'test-admin-token');
    config()->set('larasend.platform.workspace_slug', 'trooper-platform');
    config()->set('larasend.platform.router_project_slug', 'platform-router');
    config()->set('larasend.platform.mail_domain', 'trooper-mail.test');
    config()->set('larasend.platform.provider', 'cloudflare');
    config()->set('larasend.platform.cloudflare_api_token', 'cf-platform-token');
    config()->set('larasend.platform.cloudflare_account_id', 'cf-platform-account');

    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Trooper Platform', 'slug' => 'trooper-platform']);
    $workspace->users()->attach($user, ['role' => 'owner']);

    $router = Project::create(['workspace_id' => $workspace->id, 'name' => 'Platform Router', 'slug' => 'platform-router']);
    Source::create([
        'project_id' => $router->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-platform-token',
        'cloudflare_account_id' => 'cf-platform-account',
        'webhook_token' => 'router-token-'.str()->random(8),
    ]);

    return $workspace;
}

function provisionPayload(array $overrides = []): array
{
    return array_merge([
        'org_id' => 'org_123',
        'org_slug' => 'acme',
        'name' => 'Acme Inc',
        'webhook_url' => 'https://trooper.test/api/hooks/larasend/org_123',
    ], $overrides);
}

it('rejects admin calls without the token', function () {
    platformFixture();

    $this->postJson('/api/admin/orgs', provisionPayload())->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(401);
});

it('disables the admin api when no token is configured', function () {
    platformFixture();
    config()->set('larasend.admin_token', '');

    $this->withHeader('Authorization', 'Bearer anything')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(503);
});

it('provisions an org project idempotently', function () {
    $workspace = platformFixture();

    $first = $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(201)
        ->json();

    expect($first['project']['slug'])->toBe('acme');
    expect($first['api_key'])->toStartWith('ls_');
    expect($first['webhook']['signing_secret'])->toStartWith('whsec_');
    expect($first['domain']['status'])->toBe('verified');
    expect($first['router']['inbound_url'])->toContain('/api/webhooks/inbound/cloudflare/');

    $project = $workspace->projects()->where('slug', 'acme')->firstOrFail();
    expect($project->trooper_org_id)->toBe('org_123');
    expect($project->domains()->where('domain', 'trooper-mail.test')->whereNotNull('verified_at')->exists())->toBeTrue();
    expect($project->sources()->where('environment', 'prod')->first()->provider->value)->toBe('cloudflare');

    // Second call: same project, secrets NOT re-issued.
    $second = $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(200)
        ->json();

    expect($second['project']['created'])->toBeFalse();
    expect($second)->not->toHaveKey('api_key');
    expect($second['webhook'])->not->toHaveKey('signing_secret');
    expect(ApiKey::query()->where('project_id', $project->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('returns 409 when another org claims the same slug', function () {
    platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(201);

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload(['org_id' => 'org_other']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'slug_conflict');
});

it('rotates the api key only when asked', function () {
    platformFixture();

    $first = $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->json();

    $rotated = $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload(['rotate_api_key' => true]))
        ->assertStatus(200)
        ->json();

    expect($rotated['api_key'])->toStartWith('ls_');
    expect($rotated['api_key'])->not->toBe($first['api_key']);
});

it('manages inbound addresses per project', function () {
    platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertStatus(201);
    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload(['org_id' => 'org_two', 'org_slug' => 'globex', 'name' => 'Globex']))
        ->assertStatus(201);

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->putJson('/api/admin/projects/acme/inbound-addresses', [
            'address' => 'Jane.Acme@Trooper-Mail.test',
            'label' => 'Jane',
            'metadata' => ['org_id' => 'org_123', 'agent_id' => 'agent_9'],
        ])
        ->assertStatus(201);

    // Same address cannot be claimed by another project.
    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->putJson('/api/admin/projects/globex/inbound-addresses', [
            'address' => 'jane.acme@trooper-mail.test',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'address_conflict');

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->getJson('/api/admin/projects/acme/inbound-addresses')
        ->assertStatus(200)
        ->assertJsonPath('addresses.0.address', 'jane.acme@trooper-mail.test');

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->deleteJson('/api/admin/projects/acme/inbound-addresses/jane.acme@trooper-mail.test')
        ->assertStatus(200)
        ->assertJsonPath('deleted', true);
});

it('reports platform health', function () {
    platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->getJson('/api/admin/health')
        ->assertStatus(200)
        ->assertJsonPath('ok', true)
        ->assertJsonPath('workspace', 'trooper-platform')
        ->assertJsonPath('router_project', 'platform-router')
        ->assertJsonPath('router_inbound_url', fn (string $url) => str_contains($url, '/api/webhooks/inbound/cloudflare/'))
        ->assertJsonPath('router_ses_webhook_url', fn (string $url) => str_contains($url, '/api/webhooks/ses/'));
});

it('verifies every provider dns record before a custom domain becomes active', function () {
    $workspace = platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertCreated();

    $project = $workspace->projects()->where('slug', 'acme')->firstOrFail();
    $project->domains()->create([
        'domain' => 'mail.acme.test',
        'status' => 'pending',
        'dns_records' => [[
            'type' => 'TXT',
            'name' => '_trooper.mail.acme.test',
            'value' => 'verified-value',
            'status' => 'pending',
        ]],
    ]);

    Http::fake([
        'https://cloudflare-dns.com/*' => Http::response([
            'Status' => 0,
            'Answer' => [[
                'name' => '_trooper.mail.acme.test',
                'type' => 16,
                'data' => '"verified-value"',
            ]],
        ]),
    ]);

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/projects/acme/domains/mail.acme.test/verify')
        ->assertSuccessful()
        ->assertJsonPath('status', 'verified')
        ->assertJsonPath('provider', 'cloudflare');
});

it('does not enable inbound routing before provider dns verification', function () {
    $workspace = platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertCreated();

    $project = $workspace->projects()->where('slug', 'acme')->firstOrFail();
    $project->domains()->create([
        'domain' => 'pending.acme.test',
        'status' => 'pending',
        'dns_records' => [],
    ]);

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/projects/acme/domains/pending.acme.test/enable-inbound')
        ->assertConflict()
        ->assertJsonPath('code', 'domain_not_verified');
});

it('prevents two organizations from claiming the same custom domain', function () {
    $workspace = platformFixture();

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload())
        ->assertCreated();
    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/orgs', provisionPayload(['org_id' => 'org_two', 'org_slug' => 'globex', 'name' => 'Globex']))
        ->assertCreated();

    $workspace->projects()->where('slug', 'acme')->firstOrFail()->domains()->create([
        'domain' => 'customer.example',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer test-admin-token')
        ->postJson('/api/admin/projects/globex/domains', ['domain' => 'customer.example'])
        ->assertConflict()
        ->assertJsonPath('code', 'domain_conflict');
});
