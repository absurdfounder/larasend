<?php

namespace App\Services;

use App\Exceptions\CloudflareEmailSendingException;
use App\Models\Source;
use App\Models\Suppression;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CloudflareApiClient
{
    public const SUPPRESSION_MAX_DATA_PAGES = 100;

    private const SUPPRESSION_PAGE_SIZE = 100;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message_id: string|null, delivered: array<int, string>, queued: array<int, string>, permanent_bounces: array<int, string>, suppressed_recipients: array<int, string>}
     */
    public function sendEmail(Source $source, array $payload): array
    {
        $response = $this->request($source)->post('/email/sending/send', $payload);

        if (! $response->successful() || $response->json('success') !== true) {
            throw $this->sendingException($response);
        }

        $result = $response->json('result');

        if (! is_array($result)) {
            throw new CloudflareEmailSendingException(
                'Cloudflare returned an invalid Email Sending response.',
                retryable: false,
                providerContext: $this->responseContext($response),
            );
        }

        $normalized = [
            'message_id' => filled($result['message_id'] ?? null)
                ? trim((string) $result['message_id'], '<>')
                : null,
            'delivered' => $this->recipientList($result['delivered'] ?? []),
            'queued' => $this->recipientList($result['queued'] ?? []),
            'permanent_bounces' => $this->recipientList($result['permanent_bounces'] ?? []),
            'suppressed_recipients' => $this->recipientList($result['suppressed_recipients'] ?? []),
        ];

        if ($normalized['delivered'] === [] && $normalized['queued'] === []) {
            $reason = $normalized['permanent_bounces'] !== [] || $normalized['suppressed_recipients'] !== []
                ? 'Cloudflare permanently rejected every recipient.'
                : 'Cloudflare did not report any delivered or queued recipients.';

            throw new CloudflareEmailSendingException(
                $reason,
                retryable: false,
                providerContext: [
                    ...$this->responseContext($response),
                    'result' => $normalized,
                ],
            );
        }

        return $normalized;
    }

    /**
     * @return array{value: int|float|null, unit: string|null}
     */
    public function getSendingLimits(Source $source): array
    {
        $response = $this->request($source)->get('/email/sending/limits');

        $this->ensureSuccessful($response);

        $quota = $response->json('result.quota') ?? [];

        return [
            'value' => $quota['value'] ?? null,
            'unit' => $quota['unit'] ?? null,
        ];
    }

    /**
     * All account-level suppressions, paginated to exhaustion.
     *
     * @return array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>
     */
    public function listSuppressions(Source $source): array
    {
        return $this->listSuppressionsUntil($source);
    }

    /**
     * @return array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>
     */
    private function listSuppressionsUntil(Source $source, ?float $deadline = null): array
    {
        $suppressions = [];
        $page = 1;

        do {
            [$requestTimeout, $connectTimeout] = $this->suppressionRequestTimeouts($deadline);
            $response = $this->request($source, $requestTimeout, $connectTimeout)->get('/email/sending/suppression', [
                'page' => $page,
                'per_page' => self::SUPPRESSION_PAGE_SIZE,
                'order' => 'created_at',
                'direction' => 'asc',
            ]);

            $this->ensureWithinSuppressionDeadline($deadline);
            $this->ensureSuccessful($response);

            $results = $response->json('result');

            if ($response->json('success') !== true || ! is_array($results) || ! array_is_list($results)) {
                throw new RuntimeException('Cloudflare returned an invalid suppression list response.');
            }

            if ($page > self::SUPPRESSION_MAX_DATA_PAGES && $results !== []) {
                throw new RuntimeException('Cloudflare suppression pagination exceeded its safe data page limit. No destructive changes were made.');
            }

            foreach ($results as $suppression) {
                $suppressions[] = [
                    'id' => (string) ($suppression['id'] ?? ''),
                    'email' => (string) ($suppression['email'] ?? ''),
                    'reason' => (string) ($suppression['reason'] ?? ''),
                    'created_at' => $suppression['created_at'] ?? null,
                    'expires_at' => $suppression['expires_at'] ?? null,
                ];
            }

            $page++;
        } while ($results !== []
            && ($page <= self::SUPPRESSION_MAX_DATA_PAGES || count($results) === self::SUPPRESSION_PAGE_SIZE));

        return $suppressions;
    }

    /**
     * A bounded, stable account-level snapshot safe for destructive decisions.
     *
     * @return array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>
     */
    public function listStableSuppressions(Source $source, float $budgetSeconds): array
    {
        if ($budgetSeconds <= 0) {
            throw new RuntimeException('Cloudflare suppression snapshot requires a positive time budget.');
        }

        $deadline = $this->monotonicTime() + $budgetSeconds;
        $previousSnapshot = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $snapshot = $this->normalizeSuppressionSnapshot($this->listSuppressionsUntil($source, $deadline));

            if ($previousSnapshot !== null && $snapshot === $previousSnapshot) {
                return $snapshot;
            }

            $previousSnapshot = $snapshot;
        }

        throw new RuntimeException('Cloudflare suppression data changed during pagination. No destructive changes were made.');
    }

    /**
     * @param  array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>  $suppressions
     * @return array<int, array{id: string, email: string, reason: string, created_at: string|null, expires_at: string|null}>
     */
    private function normalizeSuppressionSnapshot(array $suppressions): array
    {
        return collect($suppressions)
            ->map(fn (array $suppression): array => [
                'id' => trim($suppression['id']),
                'email' => Suppression::normalizeEmail($suppression['email']),
                'reason' => trim($suppression['reason']),
                'created_at' => $suppression['created_at'],
                'expires_at' => $suppression['expires_at'],
            ])
            ->sortBy(fn (array $suppression): string => json_encode($suppression, JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * Whether the token authenticates at all (independent of permissions).
     * User-owned tokens answer on the profile verify endpoint; account-owned
     * tokens only answer on the account-scoped one.
     */
    public function tokenIsValid(Source $source): bool
    {
        $response = $this->rootRequest($source)->get('/user/tokens/verify');

        if ($response->successful() && $response->json('result.status') === 'active') {
            return true;
        }

        if (blank($source->cloudflare_account_id)) {
            return false;
        }

        $accountResponse = $this->rootRequest($source)
            ->get("/accounts/{$source->cloudflare_account_id}/tokens/verify");

        return $accountResponse->successful() && $accountResponse->json('result.status') === 'active';
    }

    /**
     * All zones the token can read, with their owning account.
     *
     * @return array<int, array{id: string, name: string, account_id: string|null, account_name: string|null}>
     */
    public function listZones(Source $source): array
    {
        $zones = [];
        $page = 1;

        do {
            $response = $this->rootRequest($source)->get('/zones', [
                'page' => $page,
                'per_page' => 50,
                'status' => 'active',
            ]);

            $this->ensureSuccessful($response);

            $results = $response->json('result') ?? [];

            foreach ($results as $zone) {
                $zones[] = [
                    'id' => (string) ($zone['id'] ?? ''),
                    'name' => (string) ($zone['name'] ?? ''),
                    'account_id' => $zone['account']['id'] ?? null,
                    'account_name' => $zone['account']['name'] ?? null,
                ];
            }

            $page++;
        } while ($results !== [] && count($results) === 50);

        return $zones;
    }

    /**
     * Find the Cloudflare zone containing the domain by walking suffixes,
     * e.g. mail.example.com -> example.com.
     *
     * @return array{id: string, name: string, account_id: string|null}|null
     */
    public function findZone(Source $source, string $domain): ?array
    {
        $labels = explode('.', $domain);

        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            $response = $this->rootRequest($source)->get('/zones', ['name' => $candidate]);

            $this->ensureSuccessful($response);

            $zone = $response->json('result.0');

            if (is_array($zone) && filled($zone['id'] ?? null)) {
                return [
                    'id' => (string) $zone['id'],
                    'name' => (string) $zone['name'],
                    'account_id' => $zone['account']['id'] ?? null,
                ];
            }

            array_shift($labels);
        }

        return null;
    }

    /**
     * Onboard the domain for Email Sending, or return it if already onboarded.
     *
     * @return array{tag: string, name: string, enabled: bool, dkim_selector: string|null}
     */
    public function findOrCreateSendingSubdomain(Source $source, string $zoneId, string $domain): array
    {
        $existing = $this->rootRequest($source)->get("/zones/{$zoneId}/email/sending/subdomains");

        $this->ensureSuccessful($existing);

        $match = collect($existing->json('result') ?? [])
            ->first(fn (array $subdomain): bool => strcasecmp((string) ($subdomain['name'] ?? ''), $domain) === 0
                && ($subdomain['enabled'] ?? false) === true);

        if ($match === null) {
            $created = $this->rootRequest($source)->post("/zones/{$zoneId}/email/sending/subdomains", [
                'name' => $domain,
            ]);

            $this->ensureSuccessful($created);

            $match = $created->json('result') ?? [];
        }

        return [
            'tag' => (string) ($match['tag'] ?? ''),
            'name' => (string) ($match['name'] ?? $domain),
            'enabled' => (bool) ($match['enabled'] ?? false),
            'dkim_selector' => $match['dkim_selector'] ?? null,
        ];
    }

    /**
     * The DNS records Cloudflare expects to exist for a sending subdomain.
     *
     * @return array<int, array{type: string, name: string, content: string, priority: int|null}>
     */
    public function getSendingSubdomainDns(Source $source, string $zoneId, string $subdomainTag): array
    {
        $response = $this->rootRequest($source)->get("/zones/{$zoneId}/email/sending/subdomains/{$subdomainTag}/dns");

        $this->ensureSuccessful($response);

        return collect($response->json('result') ?? [])
            ->map(fn (array $record): array => [
                'type' => strtoupper((string) ($record['type'] ?? 'TXT')),
                'name' => (string) ($record['name'] ?? ''),
                'content' => (string) ($record['content'] ?? ''),
                'priority' => $record['priority'] ?? null,
            ])
            ->filter(fn (array $record): bool => $record['name'] !== '' && $record['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * Create any of the expected DNS records that are missing from the zone.
     * Records that already exist are skipped silently.
     *
     * @param  array<int, array{type: string, name: string, content: string, priority: int|null}>  $records
     */
    public function ensureDnsRecords(Source $source, string $zoneId, array $records): void
    {
        foreach ($records as $record) {
            $payload = [
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => 1,
            ];

            if ($record['priority'] !== null) {
                $payload['priority'] = (int) $record['priority'];
            }

            $response = $this->rootRequest($source)->post("/zones/{$zoneId}/dns_records", $payload);

            if ($response->successful()) {
                continue;
            }

            // 81057/81058: an identical record already exists — not a failure.
            $alreadyExists = collect($response->json('errors') ?? [])
                ->contains(fn (array $error): bool => in_array($error['code'] ?? null, [81057, 81058], true));

            if (! $alreadyExists) {
                $this->ensureSuccessful($response);
            }
        }
    }

    /**
     * Upload (or overwrite) a single-file ES module Worker with plain-text
     * environment bindings. Used to deploy the inbound email passthrough.
     *
     * @param  array<string, string>  $vars
     */
    public function uploadWorker(Source $source, string $scriptName, string $code, array $vars): void
    {
        $metadata = [
            'main_module' => 'worker.js',
            'compatibility_date' => '2025-01-01',
            'bindings' => collect($vars)
                ->map(fn (string $text, string $name): array => [
                    'type' => 'plain_text',
                    'name' => $name,
                    'text' => $text,
                ])
                ->values()
                ->all(),
        ];

        $response = Http::withToken((string) $source->cloudflare_api_token)
            ->acceptJson()
            ->timeout(30)
            ->attach('metadata', json_encode($metadata, JSON_THROW_ON_ERROR), 'metadata.json', ['Content-Type' => 'application/json'])
            ->attach('worker.js', $code, 'worker.js', ['Content-Type' => 'application/javascript+module'])
            ->put("https://api.cloudflare.com/client/v4/accounts/{$source->cloudflare_account_id}/workers/scripts/{$scriptName}");

        $this->ensureSuccessful($response);
    }

    public function enableEmailRouting(Source $source, string $zoneId): void
    {
        $response = $this->rootRequest($source)->post("/zones/{$zoneId}/email/routing/enable");

        if ($response->successful()) {
            return;
        }

        // Already enabled is success for our purposes.
        $alreadyEnabled = collect($response->json('errors') ?? [])
            ->contains(fn (array $error): bool => str_contains(strtolower((string) ($error['message'] ?? '')), 'already enabled'));

        if (! $alreadyEnabled) {
            $this->ensureSuccessful($response);
        }
    }

    /**
     * Point the zone's catch-all routing rule at a Worker so every address
     * on the domain is delivered to it.
     */
    public function routeCatchAllToWorker(Source $source, string $zoneId, string $workerName): void
    {
        $response = $this->rootRequest($source)->put("/zones/{$zoneId}/email/routing/rules/catch_all", [
            'name' => 'Larasend inbound',
            'enabled' => true,
            'matchers' => [['type' => 'all']],
            'actions' => [['type' => 'worker', 'value' => [$workerName]]],
        ]);

        $this->ensureSuccessful($response);
    }

    private function request(
        Source $source,
        float $requestTimeout = 15,
        float $connectTimeout = 3,
    ): PendingRequest {
        return Http::withToken((string) $source->cloudflare_api_token)
            ->baseUrl("https://api.cloudflare.com/client/v4/accounts/{$source->cloudflare_account_id}")
            ->acceptJson()
            ->connectTimeout($connectTimeout)
            ->timeout($requestTimeout);
    }

    private function rootRequest(Source $source): PendingRequest
    {
        return Http::withToken((string) $source->cloudflare_api_token)
            ->baseUrl('https://api.cloudflare.com/client/v4')
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(15);
    }

    private function sendingException(Response $response): CloudflareEmailSendingException
    {
        $errors = collect($response->json('errors') ?? [])
            ->filter(fn (mixed $error): bool => is_array($error))
            ->map(fn (array $error): array => [
                'code' => $error['code'] ?? null,
                'message' => Str::limit((string) ($error['message'] ?? ''), 500, ''),
            ])
            ->values();
        $codes = $errors->pluck('code');
        $providerMessage = $errors->pluck('message')->filter()->implode('; ');
        $retryable = $codes->intersect([10002, 10003, 10004, 10100])->isNotEmpty()
            || in_array($response->status(), [408, 425, 429], true)
            || $response->serverError();

        $message = match (true) {
            $codes->contains(10101), $codes->contains(10103), $response->status() === 401 => 'Cloudflare rejected the Email Sending API token. Check that it is valid and uses a supported token type.',
            $codes->contains(10102) => 'The Cloudflare API token is missing the "Email Sending: Edit" permission.',
            $codes->contains(10105) => 'This Cloudflare account is not entitled to Email Sending. It requires the Workers Paid plan and Email Sending enabled.',
            $codes->contains(10203) => 'Cloudflare has disabled Email Sending for this account or sender domain. Confirm that the exact sender domain is onboarded and enabled.',
            $retryable => "Cloudflare Email Sending is temporarily unavailable (HTTP {$response->status()}).",
            $providerMessage !== '' => "Cloudflare rejected the email: {$providerMessage}",
            default => "Cloudflare Email Sending failed with status {$response->status()}.",
        };

        return new CloudflareEmailSendingException(
            $message,
            $retryable,
            $this->responseContext($response, $errors->all()),
        );
    }

    /**
     * @param  array<int, array{code: mixed, message: string}>|null  $errors
     * @return array<string, mixed>
     */
    private function responseContext(Response $response, ?array $errors = null): array
    {
        $requestId = $response->header('cf-ray') ?: $response->header('x-request-id');

        return array_filter([
            'http_status' => $response->status(),
            'request_id' => $requestId,
            'errors' => $errors ?? collect($response->json('errors') ?? [])
                ->filter(fn (mixed $error): bool => is_array($error))
                ->map(fn (array $error): array => [
                    'code' => $error['code'] ?? null,
                    'message' => Str::limit((string) ($error['message'] ?? ''), 500, ''),
                ])
                ->values()
                ->all(),
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @return array<int, string>
     */
    private function recipientList(mixed $recipients): array
    {
        if (! is_array($recipients)) {
            return [];
        }

        return collect($recipients)
            ->filter(fn (mixed $recipient): bool => is_string($recipient) && $recipient !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{float, float}
     */
    private function suppressionRequestTimeouts(?float $deadline): array
    {
        if ($deadline === null) {
            return [15.0, 3.0];
        }

        $remaining = $deadline - $this->monotonicTime();

        if ($remaining <= 0) {
            throw new RuntimeException('Cloudflare suppression snapshot exceeded its time budget. No destructive changes were made.');
        }

        $requestTimeout = min(15.0, $remaining);

        return [$requestTimeout, min(3.0, $requestTimeout)];
    }

    private function ensureWithinSuppressionDeadline(?float $deadline): void
    {
        if ($deadline !== null && $this->monotonicTime() >= $deadline) {
            throw new RuntimeException('Cloudflare suppression snapshot exceeded its time budget. No destructive changes were made.');
        }
    }

    protected function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $errors = collect($response->json('errors') ?? []);

        if ($errors->contains(fn (array $error): bool => ($error['code'] ?? null) === 10105)) {
            throw new RuntimeException('This Cloudflare account is not entitled to Email Sending. It requires the Workers Paid plan and a domain onboarded for Email Sending.');
        }

        if ($errors->contains(fn (array $error): bool => ($error['code'] ?? null) === 10102)) {
            throw new RuntimeException('The Cloudflare API token is missing the "Email Sending: Edit" permission.');
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Cloudflare rejected the API token. The token may be invalid, missing a required permission, or the account may not have Email Sending enabled (requires the Workers Paid plan).');
        }

        $message = $errors->pluck('message')->filter()->implode('; ');

        throw new RuntimeException($message !== ''
            ? "Cloudflare API error: {$message}"
            : "Cloudflare API request failed with status {$response->status()}.");
    }
}
