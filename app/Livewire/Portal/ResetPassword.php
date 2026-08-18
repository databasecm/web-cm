<?php

namespace App\Livewire\Portal;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Set a new password from an emailed reset token. Completing this ALSO verifies
 * the email (decision G): clicking the emailed link proves ownership, so the
 * deal→account setup link doubles as email verification.
 */
#[Layout('components.layouts.portal')]
class ResetPassword extends Component
{
    public string $token = '';

    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword()
    {
        $this->validate();

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user): void {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ]);

                // The emailed link proves email ownership → mark verified.
                if (! $user->hasVerifiedEmail()) {
                    $user->forceFill(['email_verified_at' => now()]);
                }

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        session()->flash('portal_status', 'Kata sandi tersimpan dan email terverifikasi. Silakan masuk.');

        return redirect()->route('portal.login');
    }

    public function render()
    {
        return view('livewire.portal.reset-password');
    }
}
