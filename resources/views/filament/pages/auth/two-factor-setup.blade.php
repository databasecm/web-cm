<x-filament-panels::page.simple>
    @if ($confirmed)
        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                2FA aktif. Simpan recovery codes berikut di tempat aman — masing-masing hanya dapat
                dipakai satu kali bila Anda kehilangan akses ke authenticator.
            </p>

            <div class="grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm dark:bg-white/5">
                @foreach ($recoveryCodes as $recoveryCode)
                    <span>{{ $recoveryCode }}</span>
                @endforeach
            </div>

            <x-filament::button tag="a" :href="filament()->getUrl()" class="w-full">
                Lanjut ke Dashboard
            </x-filament::button>
        </div>
    @else
        <div class="space-y-4">
            <div class="flex justify-center">
                {!! $this->getQrCodeSvg() !!}
            </div>

            <p class="text-center text-sm text-gray-600 dark:text-gray-300">
                Atau masukkan kunci ini secara manual:<br>
                <span class="font-mono">{{ $this->getSetupKey() }}</span>
            </p>

            <x-filament-panels::form wire:submit="confirm">
                {{ $this->form }}

                <x-filament::button type="submit" class="w-full">
                    Konfirmasi &amp; Aktifkan
                </x-filament::button>
            </x-filament-panels::form>
        </div>
    @endif
</x-filament-panels::page.simple>
