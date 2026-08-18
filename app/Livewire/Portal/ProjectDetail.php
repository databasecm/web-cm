<?php

namespace App\Livewire\Portal;

use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only project detail for the owning consumer (P-2).
 *
 * Authorization is the SAME gate the consumer API uses (ProjectController@show):
 * Gate::authorize('view', $project) → ProjectPolicy::view, which only the owning
 * consumer passes. An unknown id 404s via route-model binding; another
 * consumer's project 403s here. This is never a URL bypass of the policy.
 *
 * P-2 is display only — approve/pay/sign actions arrive in P-3+.
 */
#[Layout('components.layouts.portal')]
class ProjectDetail extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function render()
    {
        $this->project->loadCount(['designs', 'rabs', 'installments']);

        return view('livewire.portal.project-detail', [
            'hasBast' => $this->project->bast()->exists(),
        ]);
    }
}
