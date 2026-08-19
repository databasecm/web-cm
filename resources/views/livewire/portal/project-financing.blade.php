@use('App\Enums\FinancingStatus')

<div>
    <div class="mb-4">
        <a href="{{ route('portal.projects.show', $project) }}" class="text-sm text-blue-600 hover:underline">
            &larr; Kembali ke proyek
        </a>
    </div>

    <h1 class="text-2xl font-semibold mb-1">Pembiayaan</h1>
    <div class="text-gray-500 mb-6">{{ $project->title }}</div>

    @if ($flash)
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ $error }}</div>
    @endif

    {{-- Status pembiayaan --}}
    @if ($financing)
        <section class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="font-medium">{{ $financing->status->label() }}</div>
                    <div class="text-sm text-gray-500">
                        {{ $financing->bankMitra?->name }} &middot;
                        Rp {{ number_format((float) $financing->amount, 0, ',', '.') }}
                    </div>
                </div>
            </div>

            @if ($financing->statusLogs->isNotEmpty())
                <div class="mt-3 border-t border-gray-100 pt-3">
                    <div class="text-xs text-gray-500 mb-1">Riwayat status</div>
                    <ul class="text-sm text-gray-600 space-y-1">
                        @foreach ($financing->statusLogs as $log)
                            <li>{{ $log->status->label() }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- Dokumen --}}
        <section class="mb-6">
            <h2 class="font-semibold mb-3">Dokumen</h2>
            @forelse ($financing->documents as $document)
                <div class="bg-white border border-gray-200 rounded-lg p-4 mb-2 flex items-center justify-between gap-4">
                    <div>
                        <div class="font-medium">{{ $document->name }}</div>
                        <div class="text-sm text-gray-500">{{ $document->status->label() }}</div>
                    </div>
                    @if (isset($documentUrls[$document->id]))
                        <a href="{{ $documentUrls[$document->id] }}" class="text-sm text-blue-600 hover:underline">
                            Lihat
                        </a>
                    @endif
                </div>
            @empty
                <div class="text-sm text-gray-500">Belum ada dokumen.</div>
            @endforelse

            {{-- Unggah (kecuali sudah final) --}}
            @unless ($financing->status->isFinal())
                <form wire:submit="uploadDocument" class="bg-white border border-gray-200 rounded-lg p-4 mt-3 space-y-3">
                    <div>
                        <label class="block text-sm mb-1">Nama dokumen (mis. KTP, Slip Gaji)</label>
                        <input type="text" wire:model="docName" class="w-full border border-gray-300 rounded px-3 py-2">
                        @error('docName') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm mb-1">Berkas (gambar/PDF)</label>
                        <input type="file" wire:model="file" class="w-full text-sm">
                        @error('file') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="bg-gray-900 text-white rounded px-4 py-2 text-sm">Unggah dokumen</button>
                </form>
            @endunless
        </section>
    @else
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500 mb-6">
            Belum ada pengajuan pembiayaan untuk proyek ini.
        </div>
    @endif

    {{-- Ajukan (bila tak ada pengajuan aktif) --}}
    @if ($canApply)
        <section class="bg-white border border-gray-200 rounded-lg p-4">
            <h2 class="font-semibold mb-3">Ajukan pembiayaan</h2>
            <form wire:submit="apply" class="space-y-3">
                <div>
                    <label class="block text-sm mb-1">Bank mitra</label>
                    <select wire:model="bank_mitra_id" class="w-full border border-gray-300 rounded px-3 py-2">
                        <option value="">— pilih bank —</option>
                        @foreach ($banks as $bank)
                            <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                        @endforeach
                    </select>
                    @error('bank_mitra_id') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1">Jumlah (Rp)</label>
                    <input type="number" step="0.01" wire:model="amount" class="w-full border border-gray-300 rounded px-3 py-2">
                    @error('amount') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="bg-gray-900 text-white rounded px-4 py-2 text-sm">Ajukan</button>
            </form>
        </section>
    @endif
</div>
