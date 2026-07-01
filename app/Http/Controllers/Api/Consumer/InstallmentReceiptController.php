<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Installment;
use App\Services\PaymentReceiptPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the payment receipt (kuitansi) PDF for a consumer's own paid
 * installment (Fase 3-7). Thin: InstallmentPolicy::downloadReceipt restricts this
 * to the owning consumer and a paid term; the PDF mirrors the stored ledger.
 */
class InstallmentReceiptController extends Controller
{
    public function show(Installment $installment, PaymentReceiptPdf $pdf): Response
    {
        Gate::authorize('downloadReceipt', $installment);

        return $pdf->make($installment)->download($pdf->filename($installment));
    }
}
