<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised when a guest consultation token has no live session in Redis — either
 * it never existed or its TTL has lapsed (the session ended). Rendered as a
 * 404 so the stateless guest API never leaks the difference.
 */
class GuestSessionNotFoundException extends RuntimeException
{
    public function __construct(string $token = '')
    {
        parent::__construct($token === ''
            ? 'Sesi konsultasi tamu tidak ditemukan atau telah berakhir.'
            : "Sesi konsultasi tamu [{$token}] tidak ditemukan atau telah berakhir.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Sesi konsultasi tamu tidak ditemukan atau telah berakhir.',
        ], 404);
    }
}
