<div class="max-w-md mx-auto mt-10 bg-white p-6 rounded-lg shadow-sm border border-gray-100">
    <h1 class="text-xl font-semibold mb-2">Setel / atur ulang kata sandi</h1>
    <p class="text-sm text-gray-500 mb-4">
        Masukkan email akun konsumen Anda. Kami kirim tautan untuk menyetel kata sandi.
    </p>

    @if ($status)
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">{{ $status }}</div>
    @endif

    <form wire:submit="sendResetLink" class="space-y-4">
        <div>
            <label for="email" class="block text-sm mb-1">Email</label>
            <input type="email" id="email" wire:model="email" autocomplete="username"
                class="w-full border border-gray-300 rounded px-3 py-2" required>
            @error('email') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="w-full bg-gray-900 text-white rounded px-4 py-2">Kirim tautan</button>
    </form>

    <div class="mt-4 text-sm">
        <a href="{{ route('portal.login') }}" class="text-blue-600 hover:underline">Kembali ke masuk</a>
    </div>
</div>
