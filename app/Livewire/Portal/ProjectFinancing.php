<?php

namespace App\Livewire\Portal;

use App\Exceptions\FinancingException;
use App\Exceptions\MediaException;
use App\Models\Financing;
use App\Models\FinancingDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancingDocumentService;
use App\Services\FinancingService;
use App\Services\MediaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Consumer financing area (Fase portal P-6): view status, apply, and upload the
 * requirement documents — for the consumer's OWN project only.
 *
 * Everything reuses the API's gates + services (Fase 4-5); there is no lifecycle
 * logic here. The consumer can NEVER move the status, review a document, or
 * disburse — those are the bank's (and Owner/Direktur's) abilities (§6.5), and
 * the portal simply never exposes them.
 *
 *  - apply:  Gate::authorize('applyFinancing', $project) → FinancingService::apply
 *            (the model enforces one active application per project)
 *  - upload: Gate::authorize('uploadFinancingDocument', $financing) → the real
 *            binary via MediaService (private disk, ADR-0015) → FinancingDocumentService
 *  - view a document: a signed media.show URL, re-checked by FinancingDocumentPolicy
 *            on serve (media-4) — never a naked file link
 */
#[Layout('components.layouts.portal')]
class ProjectFinancing extends Component
{
    use WithFileUploads;

    public Project $project;

    public ?int $bank_mitra_id = null;

    public string $amount = '';

    public string $docName = '';

    public $file;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function apply(): void
    {
        $this->reset('flash', 'error');

        $this->validate([
            'bank_mitra_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        Gate::authorize('applyFinancing', $this->project);

        $bank = User::find($this->bank_mitra_id);
        if (! $bank?->isMitraPembiayaan()) {
            throw ValidationException::withMessages(['bank_mitra_id' => 'Bank mitra tidak valid.']);
        }

        try {
            app(FinancingService::class)->apply($this->project, auth()->user(), $bank, $this->amount);
            $this->flash = 'Pengajuan pembiayaan dibuat.';
            $this->reset('amount', 'bank_mitra_id');
        } catch (FinancingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function uploadDocument(): void
    {
        $this->reset('flash', 'error');

        $financing = $this->currentFinancing();
        abort_if($financing === null, 404);

        Gate::authorize('uploadFinancingDocument', $financing);

        if ($financing->status->isFinal()) {
            $this->error = 'Dokumen terkunci — pengajuan pembiayaan sudah final.';

            return;
        }

        $this->validate([
            'docName' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file'],
        ]);

        try {
            $key = app(MediaService::class)->store(new FinancingDocument, $this->file);
        } catch (MediaException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        app(FinancingDocumentService::class)->upload($financing, $this->docName, $key, auth()->user());

        $this->flash = 'Dokumen diunggah.';
        $this->reset('docName', 'file');
    }

    private function currentFinancing(): ?Financing
    {
        return Financing::query()->where('project_id', $this->project->id)->latest('id')->first();
    }

    public function render()
    {
        $financing = $this->currentFinancing()?->load(['bankMitra', 'statusLogs', 'documents']);

        $documentUrls = [];
        if ($financing !== null) {
            foreach ($financing->documents as $document) {
                if ($document->file !== null) {
                    $documentUrls[$document->id] = app(MediaService::class)->temporaryUrl($document);
                }
            }
        }

        return view('livewire.portal.project-financing', [
            'financing' => $financing,
            'documentUrls' => $documentUrls,
            // Apply is allowed only when there is no ACTIVE application (the model
            // enforces one active per project; a final one may be re-applied).
            'canApply' => $financing === null || $financing->status->isFinal(),
            'banks' => User::query()
                ->whereHas('role', fn ($q) => $q->where('name', Role::NAME_MITRA_PEMBIAYAAN))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
