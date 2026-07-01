<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Enums\BastParty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BastResource;
use App\Models\Bast;
use App\Services\BastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consumer records their BAST signature (Fase 3-3). Thin: BastPolicy::signCustomer
 * restricts this to the owning consumer; BastService records the signature (and,
 * when both parties have signed, flips to signed and opens the pelunasan). The
 * service is transaction-safe and idempotent, so a double submit never
 * double-unlocks.
 */
class BastSignatureController extends Controller
{
    public function store(Request $request, Bast $bast): BastResource
    {
        Gate::authorize('signCustomer', $bast);

        $bast = app(BastService::class)->recordSignature($bast, BastParty::Customer, $request->user()->id);

        return (new BastResource($bast->load(['customerSigner', 'companySigner'])))
            ->additional(['meta' => ['message' => 'Tanda tangan konsumen direkam.']]);
    }
}
