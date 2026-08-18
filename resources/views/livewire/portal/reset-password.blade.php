<div class="max-w-md mx-auto mt-10 bg-white p-6 rounded-lg shadow-sm border border-gray-100">
    <h1 class="text-xl font-semibold mb-4">Setel kata sandi baru</h1>

    <form wire:submit="resetPassword" class="space-y-4">
        <div>
            <label for="email" class="block text-sm mb-1">Email</label>
            <input type="email" id="email" wire:model="email" autocomplete="username"
                class="w-full border border-gray-300 rounded px-3 py-2" required>
            @error('email') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm mb-1">Kata sandi baru</label>
            <input type="password" id="password" wire:model="password" autocomplete="new-password"
                class="w-full border border-gray-300 rounded px-3 py-2" required>
            @error('password') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm mb-1">Ulangi kata sandi</label>
            <input type="password" id="password_confirmation" wire:model="password_confirmation"
                autocomplete="new-password" class="w-full border border-gray-300 rounded px-3 py-2" required>
        </div>

        <button type="submit" class="w-full bg-gray-900 text-white rounded px-4 py-2">Simpan kata sandi</button>
    </form>
</div>
