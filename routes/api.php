<?php

use App\Http\Controllers\Api\Consumer\BastPdfController;
use App\Http\Controllers\Api\Consumer\BastSignatureController;
use App\Http\Controllers\Api\Consumer\DesignApprovalController;
use App\Http\Controllers\Api\Consumer\InstallmentReceiptController;
use App\Http\Controllers\Api\Consumer\ProjectController;
use App\Http\Controllers\Api\Consumer\RabApprovalController;
use App\Http\Controllers\Api\Consumer\RabPdfController;
use App\Http\Controllers\Api\GuestConsultationController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (versioned: /api/v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Guest (no-login) consultation — stateless, Redis-backed, throttled.
    // No auth: the opaque token is the only handle (ADR-0003).
    Route::prefix('consultations/guest')
        ->middleware('throttle:guest-consultation')
        ->group(function () {
            Route::post('/', [GuestConsultationController::class, 'start']);
            Route::post('{token}/messages', [GuestConsultationController::class, 'append']);
            Route::get('{token}/messages', [GuestConsultationController::class, 'fetch']);
        });

    // Payment gateway callback — PUBLIC (no login). This is the gateway's
    // channel; trust comes from verifying the callback signature, not auth
    // (Fase 3-6). Verified callbacks are settled on a queue.
    Route::post('payments/webhook', PaymentWebhookController::class);

    // Consumer (Konsumen L6) channel — token auth + consumer-only; per-record
    // ownership enforced by policies (Fase 2B-7).
    Route::middleware(['auth:sanctum', 'consumer'])->group(function () {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::get('projects/{project}/designs', [ProjectController::class, 'designs']);
        Route::get('projects/{project}/rabs', [ProjectController::class, 'rabs']);
        Route::get('projects/{project}/installments', [ProjectController::class, 'installments']);
        Route::get('projects/{project}/bast', [ProjectController::class, 'bast']);
        Route::post('projects/{project}/checkout', [ProjectController::class, 'checkout']);

        Route::post('designs/{design}/approve', [DesignApprovalController::class, 'store']);
        Route::post('rabs/{rab}/approve', [RabApprovalController::class, 'store']);
        Route::get('rabs/{rab}/pdf', [RabPdfController::class, 'show']);
        Route::post('bast/{bast}/sign', [BastSignatureController::class, 'store']);
        Route::get('bast/{bast}/pdf', [BastPdfController::class, 'show']);
        Route::get('installments/{installment}/receipt', [InstallmentReceiptController::class, 'show']);
    });
});
