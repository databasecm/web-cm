<div class="max-w-md mx-auto mt-10 bg-white p-6 rounded-lg shadow-sm border border-gray-100">
    <h1 class="text-xl font-semibold mb-2">Verifikasi email</h1>
    <p class="text-sm text-gray-600 mb-4">
        Email Anda belum terverifikasi. Buka tautan penyetelan kata sandi yang kami kirim ke email Anda
        untuk mengaktifkan akun — menyelesaikannya sekaligus memverifikasi email Anda.
    </p>

    @if ($status)
        <div class="mb-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700">{{ $status }}</div>
    @endif

    <div class="flex items-center justify-between">
        <button type="button" wire:click="resend" class="bg-gray-900 text-white rounded px-4 py-2 text-sm">
            Kirim ulang tautan
        </button>
        <form method="POST" action="{{ route('portal.logout') }}">
            @csrf
            <button type="submit" class="text-sm text-red-600 hover:underline">Keluar</button>
        </form>
    </div>
</div>
