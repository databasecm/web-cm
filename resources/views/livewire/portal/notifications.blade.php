<div>
    <div class="mb-4">
        <a href="{{ route('portal.dashboard') }}" class="text-sm text-blue-600 hover:underline">
            &larr; Kembali ke daftar proyek
        </a>
    </div>

    <div class="flex items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-semibold">Notifikasi</h1>
        <div class="flex items-center gap-3 text-sm">
            <button type="button" wire:click="toggleUnread"
                class="border border-gray-300 rounded px-3 py-1.5 hover:border-gray-400">
                {{ $unreadOnly ? 'Tampilkan semua' : 'Hanya belum dibaca' }}
            </button>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead"
                    class="bg-gray-900 text-white rounded px-3 py-1.5">
                    Tandai semua dibaca
                </button>
            @endif
        </div>
    </div>

    @forelse ($notifications as $notification)
        @php($data = $notification->data ?? [])
        @php($url = $actionUrls[$notification->id] ?? null)
        <div @class([
            'bg-white border rounded-lg p-4 mb-2 flex items-start justify-between gap-4',
            'border-gray-200' => $notification->read_at !== null,
            'border-blue-200 bg-blue-50/40' => $notification->read_at === null,
        ])>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    @if ($notification->read_at === null)
                        <span class="inline-block h-2 w-2 rounded-full bg-blue-600 shrink-0"></span>
                    @endif
                    <div class="font-medium">{{ $data['title'] ?? 'Notifikasi' }}</div>
                </div>
                <div class="text-sm text-gray-600 mt-0.5">{{ $data['body'] ?? '' }}</div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $notification->created_at?->diffForHumans() }}
                </div>
                @if ($url !== null)
                    <a href="{{ $url }}" class="inline-block text-sm text-blue-600 hover:underline mt-2">
                        Lihat detail
                    </a>
                @endif
            </div>
            @if ($notification->read_at === null)
                <button type="button" wire:click="markRead('{{ $notification->id }}')"
                    class="text-sm text-gray-500 hover:text-gray-700 shrink-0">
                    Tandai dibaca
                </button>
            @endif
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
            {{ $unreadOnly ? 'Tidak ada notifikasi belum dibaca.' : 'Belum ada notifikasi.' }}
        </div>
    @endforelse

    <div class="mt-4">
        {{ $notifications->links() }}
    </div>
</div>
