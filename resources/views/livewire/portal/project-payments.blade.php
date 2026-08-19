@use('App\Enums\InstallmentStatus')

<div wire:poll.10s>
    <div class="mb-4">
        <a href="{{ route('portal.projects.show', $project) }}" class="text-sm text-blue-600 hover:underline">
            &larr; Kembali ke proyek
        </a>
    </div>

    <h1 class="text-2xl font-semibold mb-1">Pembayaran</h1>
    <div class="text-gray-500 mb-6">{{ $project->title }}</div>

    @if ($flash)
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ $error }}</div>
    @endif

    {{-- Pilih skema (hanya bila belum checkout & nilai kontrak sudah ada) --}}
    @if ($canCheckout)
        <section class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
            <h2 class="font-semibold mb-1">Pilih skema pembayaran</h2>
            <p class="text-sm text-gray-500 mb-3">Nilai kontrak: Rp {{ number_format((float) $project->contract_value, 0, ',', '.') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($schemes as $scheme)
                    <button type="button" wire:click="checkout('{{ $scheme->value }}')"
                        wire:confirm="Pilih skema {{ $scheme->label() }}? Jadwal termin akan dibuat."
                        class="border border-gray-300 rounded px-4 py-2 text-sm hover:border-gray-400">
                        {{ $scheme->label() }}
                    </button>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Jadwal termin --}}
    @if ($installments->isNotEmpty())
        <section>
            <h2 class="font-semibold mb-3">Jadwal termin</h2>
            <div class="space-y-2">
                @foreach ($installments as $installment)
                    <div class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="font-medium">
                                    Termin {{ $installment->term_no }} — {{ $installment->label }}
                                    <span class="text-gray-400">({{ (int) $installment->percentage }}%)</span>
                                </div>
                                <div class="text-sm text-gray-500">
                                    Rp {{ number_format((float) $installment->amount, 0, ',', '.') }}
                                    &middot; {{ $installment->due_condition->label() }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-medium">{{ $installment->status->label() }}</div>

                                @if ($installment->status === InstallmentStatus::Unlocked)
                                    @if ($installment->va_number)
                                        <div class="mt-1 text-xs text-gray-600">
                                            VA: <span class="font-mono">{{ $installment->va_number }}</span>
                                        </div>
                                        <div class="text-xs text-amber-600">Menunggu pembayaran…</div>
                                    @else
                                        <button type="button" wire:click="charge({{ $installment->id }})"
                                            class="mt-1 bg-gray-900 text-white rounded px-4 py-2 text-sm">
                                            Bayar
                                        </button>
                                    @endif
                                @elseif ($installment->status === InstallmentStatus::Paid)
                                    <a href="{{ route('portal.installments.receipt', $installment) }}"
                                        class="mt-1 inline-block text-xs text-blue-600 hover:underline">
                                        Unduh kwitansi
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @elseif (! $canCheckout)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
            Jadwal pembayaran belum tersedia. Skema dapat dipilih setelah RAB disetujui (nilai kontrak ditetapkan).
        </div>
    @endif
</div>
