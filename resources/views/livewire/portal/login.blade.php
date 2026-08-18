<div class="max-w-md mx-auto mt-10 bg-white p-6 rounded-lg shadow-sm border border-gray-100">
    <h1 class="text-xl font-semibold mb-4">Masuk — Portal Konsumen</h1>

    @if (session('portal_status'))
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">
            {{ session('portal_status') }}
        </div>
    @endif

    <form wire:submit="authenticate" class="space-y-4">
        <div>
            <label for="email" class="block text-sm mb-1">Email</label>
            <input type="email" id="email" wire:model="email" autocomplete="username"
                class="w-full border border-gray-300 rounded px-3 py-2" required>
            @error('email') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password" class="block text-sm mb-1">Kata sandi</label>
            <input type="password" id="password" wire:model="password" autocomplete="current-password"
                class="w-full border border-gray-300 rounded px-3 py-2" required>
            @error('password') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="remember"> Ingat saya
        </label>

        <button type="submit" class="w-full bg-gray-900 text-white rounded px-4 py-2">Masuk</button>
    </form>

    <div class="mt-4 text-sm">
        <a href="{{ route('portal.password.request') }}" class="text-blue-600 hover:underline">
            Lupa / setel kata sandi?
        </a>
    </div>
</div>
