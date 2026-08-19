<?php

namespace App\Livewire\Portal;

use App\Enums\BastParty;
use App\Models\Bast;
use App\Models\Design;
use App\Models\Project;
use App\Models\Rab;
use App\Services\BastService;
use App\Services\DesignService;
use App\Services\RabService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Project detail for the owning consumer (P-2 view + P-3 approvals).
 *
 * View authorization is the SAME gate the consumer API uses
 * (ProjectController@show): Gate::authorize('view', $project). An unknown id 404s
 * via route-model binding; another consumer's project 403s.
 *
 * The approve actions are the FIRST portal mutations. Each re-fetches the target
 * by id and re-authorizes with the SAME ability + policy as the API
 * (DesignApprovalController / RabApprovalController) before delegating to the
 * existing service — never trusting component state, never a URL/action bypass,
 * and never duplicating the state guard (policy + service already own it). The
 * business effects (e.g. RAB approval snapshotting contract_value + advancing the
 * project) come entirely from the shared service.
 */
#[Layout('components.layouts.portal')]
class ProjectDetail extends Component
{
    public Project $project;

    public ?string $flash = null;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function approveDesign(int $designId): void
    {
        $design = Design::findOrFail($designId);

        Gate::authorize('approve', $design);

        app(DesignService::class)->approve($design, auth()->user());

        $this->flash = 'Desain disetujui.';
    }

    public function approveRab(int $rabId): void
    {
        $rab = Rab::findOrFail($rabId);

        Gate::authorize('approve', $rab);

        app(RabService::class)->approve($rab, auth()->user());

        $this->flash = 'RAB disetujui.';
    }

    public function signBast(int $bastId): void
    {
        $bast = Bast::findOrFail($bastId);

        Gate::authorize('signCustomer', $bast);

        // Records the consumer signature and — once BOTH parties have signed —
        // flips the BAST to signed and opens the pelunasan term (§7). The service
        // is transaction-safe and idempotent, so a double submit never
        // double-unlocks; there is no signing/unlock logic here.
        app(BastService::class)->recordSignature($bast, BastParty::Customer, auth()->id());

        $this->flash = 'Tanda tangan Anda direkam.';
    }

    public function render()
    {
        $this->project->loadCount(['designs', 'rabs', 'installments']);

        return view('livewire.portal.project-detail', [
            'designs' => $this->project->designs()->orderByDesc('version')->get(),
            'rabs' => $this->project->rabs()->orderByDesc('version')->get(),
            'bast' => $this->project->bast()->with(['customerSigner', 'companySigner'])->first(),
        ]);
    }
}
