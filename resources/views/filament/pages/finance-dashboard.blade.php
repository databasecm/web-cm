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

    @php($projectRows = $this->getProjectRows())
    @php($unalloc = $projectPnl['unallocated'])

    <x-filament::section>
        <x-slot name="heading">Laba-Rugi per Proyek (keseluruhan)</x-slot>
        <x-slot name="description">Pemasukan (termin + pencairan) &minus; pengeluaran yang tertaut proyek.</x-slot>

        <div style="overflow-x: auto;">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pr-4">Proyek</th>
                        <th class="py-1 pr-4 text-right">Pemasukan</th>
                        <th class="py-1 pr-4 text-right">Pengeluaran</th>
                        <th class="py-1 text-right">Laba/Rugi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($projectRows as $row)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-4">{{ $row['title'] }}</td>
                            <td class="py-1 pr-4 text-right text-success-600 dark:text-success-400">{{ $row['income'] }}</td>
                            <td class="py-1 pr-4 text-right text-danger-600 dark:text-danger-400">{{ $row['expense'] }}</td>
                            <td class="py-1 text-right font-semibold">{{ $row['net'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-500 dark:text-gray-400">Belum ada transaksi tertaut proyek.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Overhead Tak Teralokasi</x-slot>
        <x-slot name="description">
            Transaksi tanpa proyek &mdash; <strong>termasuk gaji</strong>, yang lintas banyak proyek
            sehingga TIDAK diatribusikan ke proyek mana pun. Angka ini terpisah dari laba-rugi per proyek
            di atas agar tidak menyesatkan.
        </x-slot>
        <div class="flex flex-wrap gap-x-8 gap-y-1">
            <div>Pemasukan: <span class="font-medium text-success-600 dark:text-success-400">{{ $this->rupiah($unalloc['income']) }}</span></div>
            <div>Pengeluaran: <span class="font-medium text-danger-600 dark:text-danger-400">{{ $this->rupiah($unalloc['expense']) }}</span></div>
            <div>Net: <span class="font-semibold">{{ $this->rupiah($unalloc['net']) }}</span></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
