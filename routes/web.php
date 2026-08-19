<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\Portal\BastPdfController;
use App\Http\Controllers\Portal\RabPdfController;
use App\Http\Controllers\Portal\ReceiptController;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\ForgotPassword;
use App\Livewire\Portal\Login;
use App\Livewire\Portal\ProjectDetail;
use App\Livewire\Portal\ProjectFinancing;
use App\Livewire\Portal\ProjectPayments;
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

            // RAB penawaran PDF (P-3). Same downloadPdf policy + RabPenawaranPdf
            // service as the Sanctum API — only the guard differs.
            Route::get('rabs/{rab}/pdf', RabPdfController::class)->name('rabs.pdf');

            // Payments (P-4): scheme + schedule + VA charge (Livewire), and the
            // paid-term receipt PDF (same downloadReceipt policy as the API).
            Route::get('projects/{project}/payments', ProjectPayments::class)->name('projects.payments');
            Route::get('installments/{installment}/receipt', ReceiptController::class)->name('installments.receipt');

            // BAST document PDF (P-5) — signed + owner, same downloadPdf policy +
            // BastPdf service as the Sanctum API.
            Route::get('bast/{bast}/pdf', BastPdfController::class)->name('bast.pdf');

            // Financing (P-6): view/apply/upload documents (Livewire). Documents
            // themselves are served by the signed media.show route (media-4).
            Route::get('projects/{project}/financing', ProjectFinancing::class)->name('projects.financing');
        });
    });
});
