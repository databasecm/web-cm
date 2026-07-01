<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Models\Bast;
use App\Services\BastPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the BAST document PDF for a consumer's own signed BAST (Fase 3-7).
 * Thin: BastPolicy::downloadPdf restricts this to a signed BAST viewable by the
 * owning consumer.
 */
class BastPdfController extends Controller
{
    public function show(Bast $bast, BastPdf $pdf): Response
    {
        Gate::authorize('downloadPdf', $bast);

        return $pdf->make($bast)->download($pdf->filename($bast));
    }
}
