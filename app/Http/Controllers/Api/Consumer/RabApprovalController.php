<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\RabResource;
use App\Models\Rab;
use App\Services\RabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consumer approves a submitted RAB (Fase 2B-7). RabPolicy::approve restricts
 * this to the owning consumer and a submitted version; approval finalises the
 * contract via RabService (contract_value snapshot + supersede, Fase 2B-5).
 */
class RabApprovalController extends Controller
{
    public function store(Request $request, Rab $rab): RabResource
    {
        Gate::authorize('approve', $rab);

        app(RabService::class)->approve($rab, $request->user());

        return (new RabResource($rab->refresh()))
            ->additional(['meta' => ['message' => 'RAB disetujui.']]);
    }
}
