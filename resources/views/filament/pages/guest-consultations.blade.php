<x-filament-panels::page>
    {{-- No websockets: poll the Redis-backed active index every few seconds. --}}
    <div wire:poll.5s class="grid gap-6 md:grid-cols-3">
        {{-- Session list --}}
        <div class="md:col-span-1 space-y-2">
            <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400">Sesi aktif</h3>

            @forelse ($this->sessions() as $session)
                <button
                    type="button"
                    wire:click="openSession('{{ $session['token'] }}')"
                    @class([
                        'w-full rounded-lg border px-3 py-2 text-left text-sm transition',
                        'border-primary-500 bg-primary-50 dark:bg-primary-500/10' => $activeToken === $session['token'],
                        'border-gray-200 dark:border-gray-700' => $activeToken !== $session['token'],
                    ])
                >
                    <span class="flex items-center justify-between">
                        <span class="font-medium">{{ \App\Enums\Bidang::from($session['bidang'])->label() }}</span>
                        <span @class([
                            'inline-flex items-center gap-1 text-xs',
                            'text-success-600' => $session['online'],
                            'text-gray-400' => ! $session['online'],
                        ])>
                            <span @class([
                                'h-2 w-2 rounded-full',
                                'bg-success-500' => $session['online'],
                                'bg-gray-300' => ! $session['online'],
                            ])></span>
                            {{ $session['online'] ? 'online' : 'idle' }}
                        </span>
                    </span>
                    <span class="mt-1 block text-xs text-gray-500">
                        {{ $session['message_count'] }} pesan
                        @if ($session['manager_id']) · sudah diklaim @else · belum diklaim @endif
                    </span>
                </button>
            @empty
                <p class="text-sm text-gray-500">Belum ada sesi tamu aktif.</p>
            @endforelse
        </div>

        {{-- Conversation + reply --}}
        <div class="md:col-span-2">
            @if ($activeToken)
                <div class="space-y-3">
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        @forelse ($this->messages() as $message)
                            <div @class([
                                'rounded-lg px-3 py-2 text-sm',
                                'bg-primary-50 dark:bg-primary-500/10' => $message['sender_type'] === 'manager',
                                'bg-gray-50 dark:bg-white/5' => $message['sender_type'] !== 'manager',
                            ])>
                                <span class="block text-xs font-semibold text-gray-500">
                                    {{ $message['sender_type'] === 'manager' ? 'Manager' : 'Konsumen' }}
                                </span>
                                {{ $message['message'] }}
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">Sesi ini sudah berakhir atau kosong.</p>
                        @endforelse
                    </div>

                    <form wire:submit="send" class="space-y-2">
                        <textarea
                            wire:model="reply"
                            rows="3"
                            placeholder="Tulis balasan…"
                            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                        ></textarea>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                            Balas
                        </x-filament::button>
                    </form>
                </div>
            @else
                <p class="text-sm text-gray-500">Pilih sesi tamu di kiri untuk membuka percakapan.</p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
