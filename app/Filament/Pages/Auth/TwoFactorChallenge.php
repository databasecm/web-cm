<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\RendersAsSimplePage;
use App\Http\Middleware\EnforceTwoFactorAuthentication;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * One-time-per-session TOTP / recovery-code challenge for accounts that have
 * 2FA enabled. On success the session is marked cleared by
 * {@see EnforceTwoFactorAuthentication}.
 */
class TwoFactorChallenge extends Page
{
    use RendersAsSimplePage;

    protected static string $view = 'filament.pages.auth.two-factor-challenge';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $user = filament()->auth()->user();

        if ($user === null) {
            $this->redirect(filament()->getLoginUrl());

            return;
        }

        // Nothing to challenge: 2FA not enabled, or already cleared this session.
        if (! $user->hasEnabledTwoFactorAuthentication() || session('auth.two_factor_passed')) {
            $this->redirect(filament()->getUrl());

            return;
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Kode authenticator atau recovery code')
                    ->required()
                    ->autocomplete(false),
            ])
            ->statePath('data');
    }

    public function authenticate(): void
    {
        /** @var User $user */
        $user = filament()->auth()->user();
        $code = trim((string) $this->form->getState()['code']);

        if ($this->passesTotp($user, $code) || $this->consumesRecoveryCode($user, $code)) {
            session()->put('auth.two_factor_passed', true);
            $this->redirect(filament()->getUrl());

            return;
        }

        throw ValidationException::withMessages([
            'data.code' => 'Kode autentikasi tidak valid.',
        ]);
    }

    public function getHeading(): string
    {
        return 'Verifikasi Dua Langkah';
    }

    public function getSubheading(): ?string
    {
        return 'Masukkan kode 6 digit dari aplikasi authenticator Anda, atau salah satu recovery code.';
    }

    private function passesTotp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        return app(TwoFactorAuthenticationProvider::class)
            ->verify(decrypt($user->two_factor_secret), $code);
    }

    private function consumesRecoveryCode(User $user, string $code): bool
    {
        if (! in_array($code, $user->recoveryCodes(), true)) {
            return false;
        }

        $user->replaceRecoveryCode($code);

        return true;
    }
}
