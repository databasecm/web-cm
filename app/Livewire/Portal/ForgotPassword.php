<?php

namespace App\Livewire\Portal;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Request a password-setup / reset link. The message is always neutral so the
 * form never reveals which emails are registered.
 */
#[Layout('components.layouts.portal')]
class ForgotPassword extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public string $status = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // Uses the same broker as the deal→account flow (ADR-0003). Ignore the
        // specific status to avoid account enumeration.
        Password::sendResetLink(['email' => $this->email]);

        $this->status = 'Jika email terdaftar, tautan penyetelan kata sandi telah dikirim.';
        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.portal.forgot-password');
    }
}
