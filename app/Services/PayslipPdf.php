<?php

namespace App\Services;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Renders a worker's payslip into a branded PDF (Fase 6-4), reusing the dompdf
 * setup already wired for the payment receipt (ADR-0010). Figures come straight
 * from the stored Payslip and its Payroll — never recomputed — so the slip
 * mirrors what was (or will be) paid.
 */
class PayslipPdf
{
    public function make(Payslip $payslip): PdfDocument
    {
        $payslip->loadMissing(['employee', 'payroll']);

        return Pdf::loadView('pdf.payslip', [
            'payslip' => $payslip,
            'company' => config('company'),
        ])->setPaper('a4');
    }

    public function filename(Payslip $payslip): string
    {
        return 'slip-gaji-'.str_pad((string) $payslip->id, 6, '0', STR_PAD_LEFT).'.pdf';
    }
}
