<x-filament-panels::page.simple>
    <x-filament-panels::form wire:submit="authenticate">
        {{ $this->form }}

        <x-filament::button type="submit" class="w-full">
            Verifikasi
        </x-filament::button>
    </x-filament-panels::form>

    <x-slot name="subheading">
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="text-sm text-primary-600 hover:underline">
                Keluar
            </button>
        </form>
    </x-slot>
</x-filament-panels::page.simple>
