<?php

namespace App\Services;

use App\Models\Installment;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Renders a paid installment into a branded payment receipt (kuitansi) PDF (Fase
 * 3-7, ADR-0010). Figures come straight from the stored installment and its
 * cash-book Transaction — never recomputed — so the receipt mirrors the ledger.
 */
class PaymentReceiptPdf
{
    public function make(Installment $installment): PdfDocument
    {
        $installment->loadMissing(['project.konsumen']);

        return Pdf::loadView('pdf.payment-receipt', [
            'installment' => $installment,
            'transaction' => $this->transactionFor($installment),
            'company' => config('company'),
        ])->setPaper('a4');
    }

    public function filename(Installment $installment): string
    {
        return 'kuitansi-'.str_pad((string) $installment->id, 6, '0', STR_PAD_LEFT).'.pdf';
    }

    /**
     * The cash-book income row posted when this installment was paid (Fase 3-4);
     * used for the receipt number/date. May be null for legacy/edge data.
     */
    private function transactionFor(Installment $installment): ?Transaction
    {
        return Transaction::query()->forInstallments()
            ->where('reference_id', $installment->id)
            ->latest('id')
            ->first();
    }
}
