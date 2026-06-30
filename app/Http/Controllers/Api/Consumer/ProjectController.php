<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Enums\PaymentScheme;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DesignResource;
use App\Http\Resources\Api\InstallmentResource;
use App\Http\Resources\Api\ProjectResource;
use App\Http\Resources\Api\RabResource;
use App\Models\Project;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

/**
 * Consumer project endpoints (Fase 2B-7). Thin: ownership is enforced by
 * ProjectPolicy and the heavy lifting is the existing services. The channel is
 * already restricted to Konsumen by the `consumer` middleware.
 */
class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->where('konsumen_id', $request->user()->id)
            ->latest()
            ->get();

        return ProjectResource::collection($projects)->additional(['meta' => ['count' => $projects->count()]]);
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return (new ProjectResource($project))->additional(['meta' => []]);
    }

    public function designs(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $designs = $project->designs;

        return DesignResource::collection($designs)->additional(['meta' => ['count' => $designs->count()]]);
    }

    public function rabs(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $rabs = $project->rabs;

        return RabResource::collection($rabs)->additional(['meta' => ['count' => $rabs->count()]]);
    }

    public function installments(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $installments = $project->installments;

        return InstallmentResource::collection($installments)->additional(['meta' => ['count' => $installments->count()]]);
    }

    public function checkout(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('checkout', $project);

        $data = $request->validate([
            'payment_scheme' => ['required', new Enum(PaymentScheme::class)],
        ]);

        $project = app(CheckoutService::class)->checkout($project, PaymentScheme::from($data['payment_scheme']));

        return InstallmentResource::collection($project->installments)
            ->additional(['meta' => ['payment_scheme' => $project->payment_scheme->value]]);
    }
}
