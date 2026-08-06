<?php

namespace App\Http\Controllers\Api\Consumer;

use App\Exceptions\MediaException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\FinancingDocumentResource;
use App\Http\Resources\Api\FinancingResource;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\User;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Consumer financing endpoints (Fase 4-5). Thin: ownership is enforced by the
 * applyFinancing / uploadFinancingDocument gates and FinancingPolicy, and the
 * heavy lifting is the existing FinancingService / FinancingDocumentService. The
 * channel is already restricted to Konsumen by the `consumer` middleware.
 *
 * Consumers can only ever view/apply/upload on their OWN project's financing —
 * they can never move the status, review a document, or disburse (bank only).
 */
class FinancingController extends Controller
{
    /** The project's current financing application (latest). */
    public function showForProject(Project $project): FinancingResource
    {
        $financing = Financing::query()->where('project_id', $project->id)->latest('id')->first();
        abort_if($financing === null, 404, 'Belum ada pengajuan pembiayaan.');

        Gate::authorize('view', $financing);

        return (new FinancingResource($financing->load(['project', 'bankMitra'])))->additional(['meta' => []]);
    }

    /** Apply for financing on an own project. */
    public function apply(Request $request, Project $project): FinancingResource
    {
        Gate::authorize('applyFinancing', $project);

        $data = $request->validate([
            'bank_mitra_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $bank = User::find($data['bank_mitra_id']);
        if (! $bank?->isMitraPembiayaan()) {
            throw ValidationException::withMessages(['bank_mitra_id' => 'Bank mitra tidak valid.']);
        }

        $financing = app(FinancingService::class)->apply($project, $request->user(), $bank, $data['amount']);

        return (new FinancingResource($financing->load(['project', 'bankMitra'])))
            ->additional(['meta' => ['message' => 'Pengajuan pembiayaan dibuat.']]);
    }

    /** Detail + status history of an own financing. */
    public function show(Financing $financing): FinancingResource
    {
        Gate::authorize('view', $financing);

        return (new FinancingResource($financing->load(['project', 'bankMitra', 'statusLogs'])))
            ->additional(['meta' => []]);
    }

    /** Documents of an own financing. */
    public function documents(Financing $financing): AnonymousResourceCollection
    {
        Gate::authorize('view', $financing);
        $documents = $financing->documents;

        return FinancingDocumentResource::collection($documents)
            ->additional(['meta' => ['count' => $documents->count()]]);
    }

    /**
     * Upload a requirement document (KTP/payslip) on an own financing — a real
     * binary (image/PDF) stored on the private media disk (ADR-0015, Fase media-4).
     *
     * The final-financing guard runs BEFORE the file is stored (no orphan on a
     * locked application); the type/size are validated server-side by MediaService.
     */
    public function uploadDocument(Request $request, Financing $financing): FinancingDocumentResource
    {
        Gate::authorize('uploadFinancingDocument', $financing);

        if ($financing->status->isFinal()) {
            throw ValidationException::withMessages([
                'financing' => 'Dokumen terkunci — pengajuan pembiayaan sudah final.',
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file'],
        ]);

        try {
            $key = app(MediaService::class)->store(new FinancingDocument, $request->file('file'));
        } catch (MediaException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $document = app(FinancingDocumentService::class)
            ->upload($financing, $data['name'], $key, $request->user());

        return (new FinancingDocumentResource($document))
            ->additional(['meta' => ['message' => 'Dokumen diunggah.']]);
    }
}
