<?php

namespace App\Services;

use App\Models\Bast;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Renders a signed BAST into a branded handover document PDF (Fase 3-7,
 * ADR-0010). It shows the project/consumer, the handover statement, and BOTH
 * signers (name + timestamp) read from the stored signer columns (Fase 3-3).
 */
class BastPdf
{
    public function make(Bast $bast): PdfDocument
    {
        $bast->loadMissing(['project.konsumen', 'customerSigner', 'companySigner']);

        return Pdf::loadView('pdf.bast', [
            'bast' => $bast,
            'company' => config('company'),
        ])->setPaper('a4');
    }

    public function filename(Bast $bast): string
    {
        return 'bast-'.str_pad((string) $bast->id, 6, '0', STR_PAD_LEFT).'.pdf';
    }
}
