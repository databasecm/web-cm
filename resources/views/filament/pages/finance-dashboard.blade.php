<x-filament-panels::page>
    @php($composition = $this->getComposition())

    <div class="text-sm text-gray-500 dark:text-gray-400">
        Periode berjalan: {{ \Illuminate\Support\Carbon::parse($periodStart)->translatedFormat('d M Y') }}
        &ndash; {{ \Illuminate\Support\Carbon::parse($periodEnd)->translatedFormat('d M Y') }}
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Pemasukan (bulan ini)</x-slot>
            <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                {{ $this->rupiah($period['income']) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Pengeluaran (bulan ini)</x-slot>
            <div class="text-2xl font-bold text-danger-600 dark:text-danger-400">
                {{ $this->rupiah($period['expense']) }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Saldo Kas (keseluruhan)</x-slot>
            <div class="text-2xl font-bold">
                {{ $this->rupiah($balance) }}
            </div>
            <x-slot name="description">Hasil bulan ini: {{ $this->rupiah($period['net']) }}</x-slot>
        </x-filament::section>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Komposisi Pemasukan</x-slot>
            @forelse ($composition['income'] as $row)
                <div class="flex items-center justify-between py-1">
                    <span>{{ $row['label'] }}</span>
                    <span class="font-medium">{{ $row['amount'] }}</span>
                </div>
            @empty
                <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada pemasukan pada periode ini.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Komposisi Pengeluaran</x-slot>
            @forelse ($composition['expense'] as $row)
                <div class="flex items-center justify-between py-1">
                    <span>{{ $row['label'] }}</span>
                    <span class="font-medium">{{ $row['amount'] }}</span>
                </div>
            @empty
                <div class="text-sm text-gray-500 dark:text-gray-400">Belum ada pengeluaran pada periode ini.</div>
            @endforelse
        </x-filament::section>
    </div>

    <div class="text-xs text-gray-400 dark:text-gray-500">
        Catatan: laba-rugi per proyek belum tersedia &mdash; transaksi belum tertaut ke proyek
        (usulan: kolom <code>project_id</code> nullable di <code>transactions</code> untuk PO/gaji per proyek).
    </div>
</x-filament-panels::page>
