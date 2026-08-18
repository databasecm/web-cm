<div>
    <h1 class="text-2xl font-semibold mb-1">Proyek Saya</h1>
    <p class="text-gray-600 mb-6">Daftar proyek Anda bersama CV Cimandiri.</p>

    @forelse ($projects as $project)
        <a href="{{ route('portal.projects.show', $project) }}"
            class="block bg-white border border-gray-200 rounded-lg p-4 mb-3 hover:border-gray-300">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="font-medium">{{ $project->title }}</div>
                    <div class="text-sm text-gray-500">{{ $project->bidang?->label() }}</div>
                </div>
                <div class="text-right">
                    <div class="text-sm">{{ $project->status->label() }}</div>
                    <div class="text-xs text-gray-500">
                        {{ number_format((float) $project->progress_percent, 0) }}% progres
                    </div>
                </div>
            </div>
        </a>
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
            Belum ada proyek.
        </div>
    @endforelse
</div>
