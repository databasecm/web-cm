<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Media download (ADR-0015). Signed (freshness) + authenticated (web session or
// Sanctum token) so MediaController can re-check the module policy. Never public.
Route::get('/media/{type}/{id}', [MediaController::class, 'show'])
    ->middleware(['signed', 'auth:web,sanctum'])
    ->name('media.show');
