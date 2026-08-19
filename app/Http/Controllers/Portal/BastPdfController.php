<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Bast;
use App\Services\BastPdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams the BAST document PDF for the owning consumer's own SIGNED BAST, from
 * the WEB portal. Mirrors the Sanctum API's BastPdfController exactly — same
 * `downloadPdf` policy (signed + owner view) and same BastPdf service — differing
 * only in the guard the route sits behind.
 */
class BastPdfController extends Controller
{
    public function __invoke(Bast $bast, BastPdf $pdf): Response
    {
        Gate::authorize('downloadPdf', $bast);

        return $pdf->make($bast)->download($pdf->filename($bast));
    }
}
