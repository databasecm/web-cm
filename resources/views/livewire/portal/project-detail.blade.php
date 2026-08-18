<div>
    <div class="mb-4">
        <a href="{{ route('portal.dashboard') }}" class="text-sm text-blue-600 hover:underline">
            &larr; Kembali ke daftar proyek
        </a>
    </div>

    <h1 class="text-2xl font-semibold mb-1">{{ $project->title }}</h1>
    <div class="text-gray-500 mb-6">{{ $project->bidang?->label() }}</div>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">Status</div>
            <div class="font-medium">{{ $project->status->label() }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-xs text-gray-500">Progres</div>
            <div class="font-medium">{{ number_format((float) $project->progress_percent, 0) }}%</div>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-gray-500">Desain</div>
            <div class="font-medium">{{ $project->designs_count }}</div>
        </div>
        <div class="bg-white border border-gray-200 rounded-lg p-4">
            <div class="text-gray-500">RAB</div>
            <div class="font-medium">{{ $project->rabs_count }}</div>
        </div>
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
        Tampilan ini hanya-baca. Aksi (persetujuan, pembayaran, tanda tangan) menyusul.
    </p>
</div>
