<div>
    <h1 class="text-2xl font-semibold mb-2">Selamat datang, {{ auth('web')->user()->name }}</h1>
    <p class="text-gray-600">
        Ini adalah portal konsumen CV Cimandiri. Area proyek, pembayaran, BAST, dan pembiayaan
        akan tersedia di sini secara bertahap.
    </p>

    <div class="mt-6 rounded-lg border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
        Belum ada data untuk ditampilkan. (Dashboard proyek menyusul.)
    </div>
</div>
