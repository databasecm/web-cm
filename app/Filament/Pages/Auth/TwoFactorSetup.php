<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\RendersAsSimplePage;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * Forced 2FA enrollment for accounts that require it (level 1–3). Generates a
 * pending secret, shows the QR code, confirms a first TOTP code, then reveals
 * one-time recovery codes.
 */
class TwoFactorSetup extends Page
{
    use RendersAsSimplePage;

    protected static string $view = 'filament.pages.auth.two-factor-setup';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public bool $confirmed = false;

    /** @var array<int, string> */
    public array $recoveryCodes = [];

    public function mount(EnableTwoFactorAuthentication $enable): void
    {
        $user = filament()->auth()->user();

        if ($user === null) {
            $this->redirect(filament()->getLoginUrl());

            return;
        }

        // Already enrolled — nothing to set up here.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->redirect(filament()->getUrl());

            return;
        }

        // Generate a pending secret + recovery codes on first visit.
        if ($user->two_factor_secret === null) {
            $enable($user);
        }

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Kode verifikasi dari authenticator')
                    ->required()
                    ->autocomplete(false),
            ])
            ->statePath('data');
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        /** @var User $user */
        $user = filament()->auth()->user();

        try {
            $confirm($user, trim((string) $this->form->getState()['code']));
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'data.code' => 'Kode verifikasi tidak valid.',
            ]);
        }

        // Enrolled: clear the challenge for this session and reveal the codes.
        session()->put('auth.two_factor_passed', true);
        $this->recoveryCodes = $user->recoveryCodes();
        $this->confirmed = true;
    }

    public function getQrCodeSvg(): string
    {
        return filament()->auth()->user()->twoFactorQrCodeSvg();
    }

    public function getSetupKey(): string
    {
        return decrypt(filament()->auth()->user()->two_factor_secret);
    }

    public function getHeading(): string
    {
        return 'Aktifkan Verifikasi Dua Langkah';
    }

    public function getSubheading(): ?string
    {
        return 'Peran Anda mewajibkan 2FA. Pindai QR dengan aplikasi authenticator, lalu masukkan kode untuk mengaktifkan.';
    }
}
