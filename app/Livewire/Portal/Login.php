<?php

namespace App\Livewire\Portal;

use App\Models\Role;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Consumer portal login (web/session guard). Portal is Konsumen-only: a valid
 * NON-consumer login (staff) is refused a portal session and logged straight
 * back out. 2FA is not applied here (optional for L6).
 */
#[Layout('components.layouts.portal')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        // An already-authenticated consumer skips the form.
        if (Auth::guard('web')->check() && Auth::guard('web')->user()->level() === Role::LEVEL_KONSUMEN) {
            $this->redirectRoute('portal.dashboard', navigate: false);
        }
    }

    public function authenticate()
    {
        $this->validate();
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Consumer-only gate: a staff account with valid credentials must not get
        // a portal session. Log out immediately and refuse.
        if (Auth::guard('web')->user()->level() !== Role::LEVEL_KONSUMEN) {
            Auth::guard('web')->logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Akun ini bukan akun konsumen. Gunakan panel staf.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.portal.login');
    }
}
