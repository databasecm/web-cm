@use('App\Enums\DesignStatus')
@use('App\Enums\RabStatus')

<div>
    <div class="mb-4">
        <a href="{{ route('portal.dashboard') }}" class="text-sm text-blue-600 hover:underline">
            &larr; Kembali ke daftar proyek
        </a>
    </div>

    <h1 class="text-2xl font-semibold mb-1">{{ $project->title }}</h1>
    <div class="text-gray-500 mb-6">{{ $project->bidang?->label() }}</div>

    @if ($flash)
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">{{ $flash }}</div>
    @endif

    <div class="grid grid-cols-2 gap-4 mb-8">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">Status</div>
            <div class="font-medium">{{ $project->status->label() }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">Progres</div>
            <div class="font-medium">{{ number_format((float) $project->progress_percent, 0) }}%</div>
        </div>
    </div>

    <div class="mb-8">
        <a href="{{ route('portal.projects.payments', $project) }}"
            class="inline-block bg-gray-900 text-white rounded px-4 py-2 text-sm">
            Termin &amp; pembayaran
        </a>
    </div>

    {{-- Desain --}}
    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-3">Desain</h2>
        @forelse ($designs as $design)
            <div class="bg-white border border-gray-200 rounded-lg p-4 mb-2 flex items-center justify-between gap-4">
                <div>
                    <div class="font-medium">Versi {{ $design->version }}</div>
                    <div class="text-sm text-gray-500">{{ $design->status->label() }}</div>
                </div>
                @if ($design->status === DesignStatus::Submitted)
                    <button type="button" wire:click="approveDesign({{ $design->id }})"
                        wire:confirm="Setujui desain versi {{ $design->version }}?"
                        class="bg-gray-900 text-white rounded px-4 py-2 text-sm">
                        Setujui desain
                    </button>
                @endif
            </div>
        @empty
            <div class="text-sm text-gray-500">Belum ada desain.</div>
        @endforelse
    </section>

    {{-- RAB --}}
    <section class="mb-8">
        <h2 class="text-lg font-semibold mb-3">RAB (Rencana Anggaran Biaya)</h2>
        @forelse ($rabs as $rab)
            <div class="bg-white border border-gray-200 rounded-lg p-4 mb-2 flex items-center justify-between gap-4">
                <div>
                    <div class="font-medium">Versi {{ $rab->version }}</div>
                    <div class="text-sm text-gray-500">
                        {{ $rab->status->label() }} &middot; Rp {{ number_format((float) $rab->grand_total, 0, ',', '.') }}
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @if (in_array($rab->status, [RabStatus::Submitted, RabStatus::Approved], true))
                        <a href="{{ route('portal.rabs.pdf', $rab) }}" class="text-sm text-blue-600 hover:underline">
                            Unduh PDF
                        </a>
                    @endif
                    @if ($rab->status === RabStatus::Submitted)
                        <button type="button" wire:click="approveRab({{ $rab->id }})"
                            wire:confirm="Setujui RAB versi {{ $rab->version }}? Ini menetapkan nilai kontrak."
                            class="bg-gray-900 text-white rounded px-4 py-2 text-sm">
                            Setujui RAB
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-sm text-gray-500">Belum ada RAB.</div>
        @endforelse
    </section>

    {{-- Ringkasan lain --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-gray-500">Termin</div>
            <div class="font-medium">{{ $project->installments_count }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-gray-500">BAST</div>
            <div class="font-medium">{{ $hasBast ? 'Terbit' : '—' }}</div>
        </div>
    </div>

    <p class="mt-6 text-xs text-gray-400">
        Pembayaran termin dan tanda tangan BAST akan tersedia di sini.
    </p>
</div>
