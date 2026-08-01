<?php

use App\Models\Domain;
use App\Models\InboundAddress;
use App\Models\InboundEmail;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookLog;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function routingFixture(): array
{
    config()->set('larasend.platform.mail_domain', 'trooper-mail.test');

    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Trooper Platform', 'slug' => 'trooper-platform']);
    $workspace->users()->attach($user, ['role' => 'owner']);

    $routerProject = Project::create(['workspace_id' => $workspace->id, 'name' => 'Router', 'slug' => 'platform-router']);
    $routerSource = Source::create([
        'project_id' => $routerProject->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-token',
        'cloudflare_account_id' => 'cf-account',
        'webhook_token' => 'router-'.str()->random(10),
    ]);

    $acme = Project::create(['workspace_id' => $workspace->id, 'name' => 'Acme', 'slug' => 'acme']);
    $globex = Project::create(['workspace_id' => $workspace->id, 'name' => 'Globex', 'slug' => 'globex']);

    return [$routerSource, $acme, $globex];
}

function routingMime(string $to): string
{
    return implode("\r\n", [
        'From: Customer <customer@outside.test>',
        "To: {$to}",
        'Subject: Hello agent',
        'Message-ID: <msg-'.str()->random(6).'@outside.test>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Hi there!',
        '',
    ]);
}

function postInbound(Source $source, string $to)
{
    return test()->postJson("/api/webhooks/inbound/cloudflare/{$source->webhook_token}", [
        'from' => 'customer@outside.test',
        'to' => $to,
        'raw' => base64_encode(routingMime($to)),
    ]);
}

beforeEach(function () {
    Queue::fake();
});

it('routes an exact platform address into the owning project', function () {
    [$routerSource, $acme] = routingFixture();
    InboundAddress::create([
        'project_id' => $acme->id,
        'address' => 'jane.acme@trooper-mail.test',
        'label' => 'Jane',
    ]);

    postInbound($routerSource, 'jane.acme@trooper-mail.test')->assertStatus(202);

    $inbound = InboundEmail::firstOrFail();
    expect($inbound->project_id)->toBe($acme->id);
    expect($inbound->to_email)->toBe('jane.acme@trooper-mail.test');
});

it('rejects unknown addresses on the platform domain', function () {
    [$routerSource] = routingFixture();

    postInbound($routerSource, 'nobody.unknown@trooper-mail.test')
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_recipient');

    expect(InboundEmail::count())->toBe(0);
    expect(WebhookLog::query()->where('status', 'rejected')->exists())->toBeTrue();
});

it('routes custom-domain mail via the inbound-enabled domain', function () {
    [$routerSource, , $globex] = routingFixture();
    Domain::create([
        'project_id' => $globex->id,
        'domain' => 'mail.globex.test',
        'status' => 'verified',
        'verified_at' => now(),
        'inbound_enabled_at' => now(),
    ]);

    postInbound($routerSource, 'support@mail.globex.test')->assertStatus(202);

    expect(InboundEmail::firstOrFail()->project_id)->toBe($globex->id);
});

it('falls back to the source project for non-platform domains (stock behavior)', function () {
    [$routerSource] = routingFixture();

    postInbound($routerSource, 'anything@unrelated-single-tenant.test')->assertStatus(202);

    expect(InboundEmail::firstOrFail()->project_id)->toBe($routerSource->project_id);
});

it('keeps stock behavior when no platform domain is configured', function () {
    [$routerSource] = routingFixture();
    config()->set('larasend.platform.mail_domain', '');

    postInbound($routerSource, 'nobody.unknown@trooper-mail.test')->assertStatus(202);

    expect(InboundEmail::firstOrFail()->project_id)->toBe($routerSource->project_id);
});
