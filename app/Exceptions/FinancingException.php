<?php

namespace App\Exceptions;

use App\Enums\FinancingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Raised on an invalid financing operation (Fase 4-1). Renders as 422 on the API
 * so the consumer channel returns a standard error envelope (Fase 4-5).
 */
class FinancingException extends RuntimeException
{
    public static function invalidTransition(FinancingStatus $from, FinancingStatus $to): self
    {
        return new self("Transisi pembiayaan tidak sah: {$from->value} → {$to->value}.");
    }

    /**
     * One active (non-final) financing per project. A new application is refused
     * while an existing one is still in progress.
     */
    public static function alreadyActive(): self
    {
        return new self('Proyek ini masih memiliki pengajuan pembiayaan yang aktif.');
    }

    /**
     * Disbursement is only possible from an approved application.
     */
    public static function notApproved(): self
    {
        return new self('Pembiayaan hanya dapat dicairkan setelah disetujui.');
    }

    /**
     * A financing is disbursed exactly once; a second disbursement is refused so
     * the cash book never gets a duplicate income row.
     */
    public static function alreadyDisbursed(): self
    {
        return new self('Pembiayaan ini sudah dicairkan.');
    }

    /**
     * Documents of a final financing (rejected/disbursed) are immutable — they
     * can no longer be uploaded or reviewed.
     */
    public static function documentsLocked(): self
    {
        return new self('Dokumen tidak dapat diubah pada pembiayaan yang sudah final.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
