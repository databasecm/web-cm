<?php

namespace App\Livewire\Portal;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * P-1 portal shell: an authenticated, verified consumer landing page. Data areas
 * (projects, payments, BAST, financing, notifications) arrive in P-2+.
 */
#[Layout('components.layouts.portal')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.portal.dashboard');
    }
}
