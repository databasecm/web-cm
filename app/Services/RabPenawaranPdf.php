<?php

namespace App\Services;

use App\Models\Rab;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Renders a RAB into a branded penawaran PDF (Fase 2B-9, ADR-0010).
 *
 * Every figure is read straight from the RAB's snapshot columns (totals, the
 * margin/PPN/overhead rates) and its frozen line items — never recomputed from
 * live AHSAP — so the PDF is an exact mirror of the frozen quote (ADR-0007).
 */
class RabPenawaranPdf
{
    public function make(Rab $rab): PdfDocument
    {
        $rab->loadMissing(['project.konsumen', 'items']);

        return Pdf::loadView('pdf.rab-penawaran', [
            'rab' => $rab,
            'company' => config('company'),
        ])->setPaper('a4');
    }

    /**
     * Suggested download file name, e.g. penawaran-RAB-00007-v2.pdf.
     */
    public function filename(Rab $rab): string
    {
        return 'penawaran-RAB-'.str_pad((string) $rab->id, 5, '0', STR_PAD_LEFT).'-v'.$rab->version.'.pdf';
    }
}
