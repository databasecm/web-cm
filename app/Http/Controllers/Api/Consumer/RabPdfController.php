<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Rab;
use App\Services\RabPenawaranPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the penawaran PDF for a consumer's own RAB (Fase 2B-9). Thin:
 * RabPolicy::downloadPdf restricts this to the owning consumer and a
 * submitted/approved version; the PDF mirrors the frozen RAB snapshot.
 */
class RabPdfController extends Controller
{
    public function show(Rab $rab, RabPenawaranPdf $pdf): Response
    {
        Gate::authorize('downloadPdf', $rab);

        return $pdf->make($rab)->download($pdf->filename($rab));
    }
}
