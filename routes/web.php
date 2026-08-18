<?php

use App\Http\Controllers\MediaController;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\ForgotPassword;
use App\Livewire\Portal\Login;
use App\Livewire\Portal\ProjectDetail;
use App\Livewire\Portal\ResetPassword;
use App\Livewire\Portal\VerifyEmailNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Media download (ADR-0015). Signed (freshness) + authenticated (web session or
// Sanctum token) so MediaController can re-check the module policy. Never public.
Route::get('/media/{type}/{id}', [MediaController::class, 'show'])
    ->middleware(['signed', 'auth:web,sanctum'])
    ->name('media.show');

/*
|--------------------------------------------------------------------------
| Consumer portal (Fase portal P-1)
|--------------------------------------------------------------------------
| Session-authenticated area for Konsumen (L6), fully separate from the staff
| Filament panel at /sistem. Guest pages (login / password setup) are open; the
| authenticated area requires the web guard + consumer-only + verified email.
| Data areas (dashboard etc.) arrive in P-2+; P-1 is auth + shell only.
*/
Route::prefix('portal')->name('portal.')->group(function () {
    // Guest-facing pages (also reachable while logged in; the components redirect
    // an already-authenticated consumer to the dashboard).
    Route::get('login', Login::class)->name('login');
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');

    // Authenticated consumer (web guard). Email verification is NOT required here
    // so an unverified consumer can still reach the notice/resend page.
    Route::middleware(['auth:web', 'consumer.web'])->group(function () {
        Route::get('email/verify', VerifyEmailNotice::class)->name('verification.notice');

        Route::post('logout', function (Request $request) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login');
        })->name('logout');

        // Verified consumers only. `verified` redirects the unverified to the
        // notice route above.
        Route::middleware('verified:portal.verification.notice')->group(function () {
            Route::get('/', Dashboard::class)->name('dashboard');

            // Read-only project views (P-2). Ownership is enforced by
            // ProjectPolicy::view inside the component (same gate as the API).
            Route::get('projects/{project}', ProjectDetail::class)->name('projects.show');
        });
    });
});
