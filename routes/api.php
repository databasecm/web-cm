<?php

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
});
