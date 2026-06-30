<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised on an invalid checkout (Fase 2B-5). Renders as 422 on the API so the
 * consumer channel returns a standard error envelope (Fase 2B-7).
 */
class CheckoutException extends RuntimeException
{
    public static function noContractValue(): self
    {
        return new self('Checkout memerlukan RAB yang sudah disetujui (nilai kontrak belum ada).');
    }

    public static function alreadyCheckedOut(): self
    {
        return new self('Proyek ini sudah checkout (jadwal termin sudah dibuat).');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
