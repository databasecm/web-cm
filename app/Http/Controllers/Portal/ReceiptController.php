<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Installment;
use App\Services\PaymentReceiptPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the payment receipt (kuitansi) PDF for the owning consumer's own PAID
 * installment, from the WEB portal. Mirrors the Sanctum API's
 * InstallmentReceiptController exactly — same `downloadReceipt` policy (owner +
 * paid) and same PaymentReceiptPdf service — differing only in the guard.
 */
class ReceiptController extends Controller
{
    public function __invoke(Installment $installment, PaymentReceiptPdf $pdf): Response
    {
        Gate::authorize('downloadReceipt', $installment);

        return $pdf->make($installment)->download($pdf->filename($installment));
    }
}
