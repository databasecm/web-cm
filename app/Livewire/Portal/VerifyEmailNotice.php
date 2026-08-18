<?php

namespace App\Livewire\Portal;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Shown to a logged-in consumer whose email is not yet verified (the `verified`
 * middleware redirects here). Consumers verify by completing the password-setup
 * link (ADR-0003 / decision G), so "resend" re-sends that same setup link.
 */
#[Layout('components.layouts.portal')]
class VerifyEmailNotice extends Component
{
    public string $status = '';

    public function mount()
    {
        if (auth()->user()->hasVerifiedEmail()) {
            return redirect()->route('portal.dashboard');
        }
    }

    public function resend(): void
    {
        Password::sendResetLink(['email' => auth()->user()->email]);

        $this->status = 'Tautan penyetelan kata sandi telah dikirim ulang ke email Anda.';
    }

    public function render()
    {
        return view('livewire.portal.verify-email');
    }
}
