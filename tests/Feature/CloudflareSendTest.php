<?php

use App\Exceptions\CloudflareEmailSendingException;
use App\Jobs\SendQueuedEmail;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Providers\EmailProviderFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: Workspace, 1: Project, 2: Source, 3: string}
 */
function cloudflareProjectFixture(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Acme CF', 'slug' => 'acme-cf']);
    $workspace->users()->attach($user, ['role' => 'owner']);
    $project = Project::create(['workspace_id' => $workspace->id, 'name' => 'driftwood', 'slug' => 'driftwood']);
    $source = Source::create([
        'project_id' => $project->id,
        'name' => 'Production',
        'environment' => 'prod',
        'provider' => 'cloudflare',
        'cloudflare_api_token' => 'cf-test-token',
        'cloudflare_account_id' => 'acc-1234567890',
        'default_from_email' => 'receipts@example.com',
        'last_quota' => [
            'max_24_hour_send' => 5000,
            'max_send_rate' => null,
            'sent_last_24_hours' => null,
            'period' => 'day',
        ],
        'last_quota_checked_at' => now(),
        'webhook_token' => 'token-'.str()->random(8),
    ]);
    $project->domains()->create([
        'domain' => 'example.com',
        'status' => 'verified',
        'dns_records' => [],
        'verified_at' => now(),
    ]);
    $issued = ApiKey::issue($project, 'Test key', $source);

    return [$workspace, $project, $source, $issued['plain_text']];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function queueCloudflareEmail(string $token, array $overrides = []): Email
{
    Queue::fake();

    test()->withToken($token)->postJson('/api/emails', [
        'from' => 'Larasend <receipts@example.com>',
        'to' => ['Maya <maya@example.com>'],
        'subject' => 'Cloudflare welcome',
        'html' => '<h1>Hello Maya</h1>',
        'text' => 'Hello Maya',
        ...$overrides,
    ])->assertAccepted();

    return Email::query()->firstOrFail();
}

it('sends the canonical mime through cloudflare https with the complete envelope', function () {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    $sentRequest = null;
    Http::fake(function (Request $request) use (&$sentRequest) {
        $sentRequest = $request;

        return Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                'message_id' => '<cf-message-1@example.com>',
                'delivered' => ['maya@example.com', 'copy@example.com', 'hidden@example.com'],
                'queued' => [],
                'permanent_bounces' => [],
                'suppressed_recipients' => [],
            ],
        ]);
    });

    $email = queueCloudflareEmail($token, [
        'cc' => ['Copy <copy@example.com>'],
        'bcc' => ['Hidden <hidden@example.com>'],
        'reply_to' => 'Support <support@example.com>',
        'headers' => [
            'In-Reply-To' => '<original@example.net>',
            'References' => '<first@example.net> <original@example.net>',
            'X-Tenant-Marker' => 'driftwood',
        ],
        'attachments' => [[
            'filename' => 'invoice.txt',
            'content_type' => 'text/plain',
            'content' => base64_encode('invoice body'),
        ]],
    ]);

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    expect($sentRequest)->toBeInstanceOf(Request::class)
        ->and($sentRequest->method())->toBe('POST')
        ->and($sentRequest->url())->toBe('https://api.cloudflare.com/client/v4/accounts/acc-1234567890/email/sending/send')
        ->and($sentRequest->hasHeader('Authorization', 'Bearer cf-test-token'))->toBeTrue()
        ->and($sentRequest->data()['from'])->toBe([
            'address' => 'receipts@example.com',
            'name' => 'Larasend',
        ])
        ->and($sentRequest->data()['to'])->toBe([[
            'address' => 'maya@example.com',
            'name' => 'Maya',
        ]])
        ->and($sentRequest->data()['cc'])->toBe([[
            'address' => 'copy@example.com',
            'name' => 'Copy',
        ]])
        ->and($sentRequest->data()['bcc'])->toBe([
            'hidden@example.com',
        ])
        ->and($sentRequest->data())->not->toHaveKey('mime_message')
        ->and($sentRequest->data()['reply_to'])->toBe([
            'address' => 'support@example.com',
            'name' => 'Support',
        ])
        ->and($sentRequest->data()['headers']['In-Reply-To'])->toBe('<original@example.net>')
        ->and($sentRequest->data()['headers']['References'])->toBe('<first@example.net> <original@example.net>')
        ->and($sentRequest->data()['headers']['X-Tenant-Marker'])->toBe('driftwood')
        ->and($sentRequest->data()['attachments'][0])->toBe([
            'content' => base64_encode('invoice body'),
            'filename' => 'invoice.txt',
            'type' => 'text/plain',
            'disposition' => 'attachment',
        ]);

    $event = $email->events()->where('event_type', 'send')->firstOrFail();

    expect($email->fresh())
        ->workspace_id->toBe($workspace->id)
        ->project_id->toBe($project->id)
        ->source_id->toBe($source->id)
        ->status->toBe('sent')
        ->ses_message_id->toBe('cf-message-1@example.com')
        ->and($event->payload['provider'])->toBe('cloudflare')
        ->and($event->payload['transport'])->toBe('https_rest')
        ->and($event->payload['delivery_state'])->toBe('delivered')
        ->and($event->payload['delivered'])->toContain('hidden@example.com');
});

it('records a cloudflare queued response as accepted for delivery', function () {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/send' => Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                'message_id' => '<cf-queued-1@example.com>',
                'delivered' => [],
                'queued' => ['maya@example.com'],
                'permanent_bounces' => [],
                'suppressed_recipients' => [],
            ],
        ]),
    ]);

    $email = queueCloudflareEmail($token);

    (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));

    $event = $email->events()->where('event_type', 'send')->firstOrFail();

    expect($email->fresh()->status)->toBe('sent')
        ->and($email->fresh()->ses_message_id)->toBe('cf-queued-1@example.com')
        ->and($event->payload['delivery_state'])->toBe('queued')
        ->and($event->payload['queued'])->toBe(['maya@example.com'])
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
});

it('fails permanent cloudflare api rejections without retrying the job', function () {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/send' => Http::response([
            'success' => false,
            'errors' => [[
                'code' => 10203,
                'message' => 'email.sending.error.email.sending_disabled',
            ]],
            'messages' => [],
            'result' => null,
        ], 403, ['cf-ray' => 'test-ray-123']),
    ]);

    $email = queueCloudflareEmail($token);
    $job = (new SendQueuedEmail($email->id))->withFakeQueueInteractions();

    $job->handle(app(EmailProviderFactory::class));

    $job->assertFailedWith(CloudflareEmailSendingException::class);
    $failure = $email->events()->where('event_type', 'failed')->firstOrFail();

    expect($email->fresh()->status)->toBe('failed')
        ->and($email->events()->where('event_type', 'failed')->count())->toBe(1)
        ->and($failure->payload['error'])->toContain('exact sender domain')
        ->and($failure->payload['provider'])->toBe('cloudflare')
        ->and($failure->payload['retryable'])->toBeFalse()
        ->and($failure->payload['provider_context']['http_status'])->toBe(403)
        ->and($failure->payload['provider_context']['request_id'])->toBe('test-ray-123')
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
});

it('fails a successful response when cloudflare accepts no recipients', function () {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/send' => Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => [
                'message_id' => '<cf-rejected-1@example.com>',
                'delivered' => [],
                'queued' => [],
                'permanent_bounces' => [],
                'suppressed_recipients' => ['maya@example.com'],
            ],
        ]),
    ]);

    $email = queueCloudflareEmail($token);
    $job = (new SendQueuedEmail($email->id))->withFakeQueueInteractions();

    $job->handle(app(EmailProviderFactory::class));

    $job->assertFailedWith(CloudflareEmailSendingException::class);
    $failure = $email->events()->where('event_type', 'failed')->firstOrFail();

    expect($email->fresh()->status)->toBe('failed')
        ->and($failure->payload['error'])->toContain('permanently rejected every recipient')
        ->and($failure->payload['provider_context']['result']['suppressed_recipients'])->toBe(['maya@example.com'])
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
});

it('rethrows transient cloudflare api failures so the queue can retry', function (int $status, int $code) {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/send' => Http::response([
            'success' => false,
            'errors' => [[
                'code' => $code,
                'message' => 'email.sending.error.temporary',
            ]],
            'messages' => [],
            'result' => null,
        ], $status),
    ]);

    $email = queueCloudflareEmail($token);
    $caught = null;

    try {
        (new SendQueuedEmail($email->id))->handle(app(EmailProviderFactory::class));
    } catch (CloudflareEmailSendingException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(CloudflareEmailSendingException::class)
        ->and($caught->retryable)->toBeTrue()
        ->and($caught->providerContext['http_status'])->toBe($status)
        ->and($email->fresh()->status)->toBe('sending')
        ->and($email->events()->where('event_type', 'failed')->exists())->toBeFalse()
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
})->with([
    'rate limited' => [429, 10004],
    'provider unavailable' => [503, 10100],
]);

it('treats a malformed successful cloudflare response as permanent', function () {
    [$workspace, $project, $source, $token] = cloudflareProjectFixture();

    Http::preventStrayRequests();
    Http::fake([
        'https://api.cloudflare.com/client/v4/accounts/*/email/sending/send' => Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => null,
        ]),
    ]);

    $email = queueCloudflareEmail($token);
    $job = (new SendQueuedEmail($email->id))->withFakeQueueInteractions();

    $job->handle(app(EmailProviderFactory::class));

    $job->assertFailedWith(CloudflareEmailSendingException::class);

    expect($email->fresh()->status)->toBe('failed')
        ->and($email->events()->where('event_type', 'failed')->firstOrFail()->payload['error'])
        ->toContain('invalid Email Sending response')
        ->and($workspace)->toBeInstanceOf(Workspace::class)
        ->and($project)->toBeInstanceOf(Project::class)
        ->and($source)->toBeInstanceOf(Source::class);
});
