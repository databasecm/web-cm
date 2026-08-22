<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'CV Cimandiri' }} — Build-Tech Bogor</title>
    <meta name="description" content="CV. Cimandiri — perusahaan build-tech asal Bogor (2008). Lima unit usaha: furniture, konstruksi, IT, dan survey/pemetaan.">

    {{-- Built assets are optional for behaviour: only load when present so the
         page still renders in tests and on an unbuilt local. --}}
    @if (! app()->runningUnitTests() && file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
            <a href="{{ route('landing') }}" class="font-semibold text-lg">CV Cimandiri</a>
            <a href="{{ route('portal.login') }}"
                class="bg-gray-900 text-white rounded px-4 py-2 text-sm hover:bg-gray-800">
                Masuk portal konsumen
            </a>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="border-t border-gray-200 bg-white">
        <div class="max-w-6xl mx-auto px-4 py-8 text-sm text-gray-500 flex flex-col sm:flex-row items-center justify-between gap-2">
            <div>&copy; {{ date('Y') }} CV. Cimandiri — Bogor, sejak 2008.</div>
            <a href="{{ route('portal.login') }}" class="text-blue-600 hover:underline">Sudah punya akun? Masuk portal</a>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
