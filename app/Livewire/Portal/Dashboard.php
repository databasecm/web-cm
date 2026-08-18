<?php

namespace App\Livewire\Portal;

use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Consumer portal home (P-2): the consumer's OWN projects, read-only.
 *
 * The list is scoped to the authenticated consumer with the same ownership rule
 * the API uses (ProjectController@index, Fase 2B-7) — never a raw Project::all()
 * filtered in the view. Per-project authorization for the detail page is a
 * separate Gate::authorize (see ProjectDetail).
 */
#[Layout('components.layouts.portal')]
class Dashboard extends Component
{
    public function render()
    {
        $projects = Project::query()
            ->where('konsumen_id', auth()->id())
            ->latest()
            ->get();

        return view('livewire.portal.dashboard', ['projects' => $projects]);
    }
}
