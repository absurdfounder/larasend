<?php

use App\Http\Controllers\Api\Admin\AdminDomainController;
use App\Http\Controllers\Api\Admin\AdminInboundAddressController;
use App\Http\Controllers\Api\Admin\AdminProvisionController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\SuppressionController;
use App\Http\Controllers\Webhooks\CloudflareInboundController;
use App\Http\Controllers\Webhooks\SesWebhookController;
use App\Http\Middleware\AuthenticateLarasendAdminToken;
use App\Http\Middleware\AuthenticateLarasendApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateLarasendApiKey::class)->group(function () {
    Route::get('emails', [EmailController::class, 'index'])->name('api.emails.index');
    Route::post('emails', [EmailController::class, 'store'])->name('api.emails.store');
    Route::get('emails/{email}', [EmailController::class, 'show'])->name('api.emails.show');
    Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('api.suppressions.destroy');
});

Route::post('webhooks/ses/{token}', SesWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ses');

Route::post('webhooks/inbound/cloudflare/{token}', CloudflareInboundController::class)
    ->middleware('throttle:240,1')
    ->name('webhooks.inbound.cloudflare');

// Trooper platform admin API. Off (503) until LARASEND_ADMIN_TOKEN is set.
// These routes bypass AuthenticateLarasendApiKey, so its hardcoded scope map
// needs no additions; any FUTURE project-key-authed route still does.
Route::middleware(AuthenticateLarasendAdminToken::class)->prefix('admin')->group(function () {
    Route::get('health', [AdminProvisionController::class, 'health'])->name('api.admin.health');
    Route::post('orgs', [AdminProvisionController::class, 'store'])->name('api.admin.orgs.store');
    Route::get('orgs/{slug}', [AdminProvisionController::class, 'show'])->name('api.admin.orgs.show');
    Route::get('inbound/worker', [AdminDomainController::class, 'worker'])->name('api.admin.inbound.worker');
    Route::post('projects/{slug}/domains', [AdminDomainController::class, 'store'])->name('api.admin.domains.store');
    Route::get('projects/{slug}/domains/{domain}', [AdminDomainController::class, 'show'])->name('api.admin.domains.show');
    Route::post('projects/{slug}/domains/{domain}/enable-inbound', [AdminDomainController::class, 'enableInbound'])->name('api.admin.domains.enable-inbound');
    Route::get('projects/{slug}/inbound-addresses', [AdminInboundAddressController::class, 'index'])->name('api.admin.inbound-addresses.index');
    Route::put('projects/{slug}/inbound-addresses', [AdminInboundAddressController::class, 'upsert'])->name('api.admin.inbound-addresses.upsert');
    Route::delete('projects/{slug}/inbound-addresses/{address}', [AdminInboundAddressController::class, 'destroy'])->name('api.admin.inbound-addresses.destroy');
});
