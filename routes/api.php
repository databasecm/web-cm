<?php

use App\Http\Controllers\Api\Consumer\DesignApprovalController;
use App\Http\Controllers\Api\Consumer\ProjectController;
use App\Http\Controllers\Api\Consumer\RabApprovalController;
use App\Http\Controllers\Api\GuestConsultationController;
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

    // Consumer (Konsumen L6) channel — token auth + consumer-only; per-record
    // ownership enforced by policies (Fase 2B-7).
    Route::middleware(['auth:sanctum', 'consumer'])->group(function () {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::get('projects/{project}/designs', [ProjectController::class, 'designs']);
        Route::get('projects/{project}/rabs', [ProjectController::class, 'rabs']);
        Route::get('projects/{project}/installments', [ProjectController::class, 'installments']);
        Route::post('projects/{project}/checkout', [ProjectController::class, 'checkout']);

        Route::post('designs/{design}/approve', [DesignApprovalController::class, 'store']);
        Route::post('rabs/{rab}/approve', [RabApprovalController::class, 'store']);
    });
});
