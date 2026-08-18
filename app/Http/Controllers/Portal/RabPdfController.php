<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Rab;
use App\Services\RabPenawaranPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the penawaran PDF for the owning consumer's own RAB, from the WEB
 * (session) portal. Mirrors the Sanctum API's RabPdfController exactly — same
 * `downloadPdf` policy (owner + submitted/approved) and same RabPenawaranPdf
 * service — differing only in the guard the route sits behind. No new logic.
 */
class RabPdfController extends Controller
{
    public function __invoke(Rab $rab, RabPenawaranPdf $pdf): Response
    {
        Gate::authorize('downloadPdf', $rab);

        return $pdf->make($rab)->download($pdf->filename($rab));
    }
}
