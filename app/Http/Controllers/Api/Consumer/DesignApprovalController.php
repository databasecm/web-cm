<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DesignResource;
use App\Models\Design;
use App\Services\DesignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consumer approves a submitted design version (Fase 2B-7). DesignPolicy::approve
 * already restricts this to the owning consumer and a submitted version, so a
 * non-owner or non-submitted design is rejected before the service runs.
 */
class DesignApprovalController extends Controller
{
    public function store(Request $request, Design $design): DesignResource
    {
        Gate::authorize('approve', $design);

        app(DesignService::class)->approve($design, $request->user());

        return (new DesignResource($design->refresh()))
            ->additional(['meta' => ['message' => 'Desain disetujui.']]);
    }
}
