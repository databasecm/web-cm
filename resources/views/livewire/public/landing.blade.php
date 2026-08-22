@use('App\Enums\SenderType')

<div>
    {{-- Hero --}}
    <section class="bg-white border-b border-gray-200">
        <div class="max-w-6xl mx-auto px-4 py-16 text-center">
            <div class="text-sm font-medium text-blue-600 mb-2">Build-Tech · Bogor · sejak 2008</div>
            <h1 class="text-3xl sm:text-4xl font-bold mb-4">CV. Cimandiri</h1>
            <p class="max-w-2xl mx-auto text-gray-600">
                Satu perusahaan, lima unit usaha — dari furniture dan konstruksi hingga teknologi
                informasi dan pemetaan. Dari konsultasi, desain, hingga serah terima, kami kawal
                setiap tahap proyek Anda.
            </p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <a href="#konsultasi" class="bg-gray-900 text-white rounded px-5 py-2.5 text-sm hover:bg-gray-800">
                    Mulai konsultasi
                </a>
                <a href="{{ route('portal.login') }}"
                    class="border border-gray-300 rounded px-5 py-2.5 text-sm hover:border-gray-400">
                    Masuk portal konsumen
                </a>
            </div>
        </div>
    </section>

    {{-- Unit usaha --}}
    <section class="max-w-6xl mx-auto px-4 py-14">
        <h2 class="text-2xl font-semibold text-center mb-2">Unit Usaha</h2>
        <p class="text-center text-gray-500 mb-8">Lima lini yang saling melengkapi di bawah CV. Cimandiri.</p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($units as $unit)
                <div class="bg-white border border-gray-200 rounded-lg p-5">
                    <div class="flex items-center gap-3 mb-3">
                        @php($logoSvg = public_path("images/units/{$unit['key']}.svg"))
                        @php($logoPng = public_path("images/units/{$unit['key']}.png"))
                        @if (file_exists($logoSvg) || file_exists($logoPng))
                            <img src="{{ asset('images/units/'.$unit['key'].(file_exists($logoSvg) ? '.svg' : '.png')) }}"
                                alt="Logo {{ $unit['name'] }}" class="h-10 w-10 object-contain">
                        @else
                            <div class="h-10 w-10 shrink-0 rounded-md bg-gray-900 text-white flex items-center justify-center font-semibold">
                                {{ strtoupper(substr($unit['name'], 0, 1)) }}
                            </div>
                        @endif
                        <div>
                            <div class="font-semibold leading-tight">{{ $unit['name'] }}</div>
                            <div class="text-xs text-gray-500">{{ $unit['tagline'] }}</div>
                        </div>
                    </div>
                    <p class="text-sm text-gray-600">{{ $unit['description'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Konsultasi tamu (tanpa login) --}}
    <section id="konsultasi" class="bg-white border-t border-gray-200">
        <div class="max-w-2xl mx-auto px-4 py-14">
            <h2 class="text-2xl font-semibold text-center mb-2">Konsultasi Gratis</h2>
            <p class="text-center text-gray-500 mb-8">
                Kirim pertanyaan tanpa perlu membuat akun. Tim unit terkait akan membalas di jendela ini.
            </p>

            @if ($error)
                <div class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ $error }}</div>
            @endif

            @if ($token === null)
                {{-- Form awal --}}
                <form wire:submit="startConsultation" class="bg-gray-50 border border-gray-200 rounded-lg p-5 space-y-4">
                    <div>
                        <label class="block text-sm mb-1">Unit yang dituju</label>
                        <select wire:model="consultBidang" class="w-full border border-gray-300 rounded px-3 py-2">
                            <option value="">— pilih unit —</option>
                            @foreach ($consultBidangOptions as $bidang)
                                <option value="{{ $bidang->value }}">{{ $bidang->label() }}</option>
                            @endforeach
                        </select>
                        @error('consultBidang') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm mb-1">Pesan Anda</label>
                        <textarea wire:model="consultMessage" rows="4"
                            class="w-full border border-gray-300 rounded px-3 py-2"
                            placeholder="Ceritakan kebutuhan proyek Anda…"></textarea>
                        @error('consultMessage') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="bg-gray-900 text-white rounded px-5 py-2.5 text-sm hover:bg-gray-800">
                        Kirim pesan
                    </button>
                </form>
            @else
                {{-- Jendela percakapan (ephemeral, Redis) --}}
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-5" wire:poll.5s="poll">
                    <div class="text-xs text-gray-500 mb-3">
                        Sesi konsultasi aktif. Percakapan ini bersifat sementara dan tidak disimpan.
                    </div>
                    <div class="space-y-2 mb-4 max-h-80 overflow-y-auto">
                        @foreach ($messages as $message)
                            <div @class([
                                'rounded px-3 py-2 text-sm max-w-[85%]',
                                'bg-blue-600 text-white ml-auto' => ($message['sender_type'] ?? null) === SenderType::Konsumen->value,
                                'bg-white border border-gray-200' => ($message['sender_type'] ?? null) !== SenderType::Konsumen->value,
                            ])>
                                {{ $message['message'] ?? '' }}
                            </div>
                        @endforeach
                    </div>
                    <form wire:submit="sendReply" class="flex items-end gap-2">
                        <div class="flex-1">
                            <textarea wire:model="consultMessage" rows="2"
                                class="w-full border border-gray-300 rounded px-3 py-2"
                                placeholder="Tulis pesan…"></textarea>
                            @error('consultMessage') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="bg-gray-900 text-white rounded px-4 py-2 text-sm hover:bg-gray-800">
                            Kirim
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </section>
</div>
