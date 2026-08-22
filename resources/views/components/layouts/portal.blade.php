<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Portal Konsumen' }} — CV Cimandiri</title>

    {{-- Styling is optional for behaviour: only load the built assets when present
         (skipped in tests / unbuilt local so pages still render). --}}
    @if (! app()->runningUnitTests() && file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    @auth('web')
        @if (auth('web')->user()->level() === \App\Models\Role::LEVEL_KONSUMEN)
            <nav class="bg-white border-b border-gray-200">
                <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
                    <a href="{{ route('portal.dashboard') }}" class="font-semibold">
                        CV Cimandiri — Portal Konsumen
                    </a>
                    <div class="flex items-center gap-4 text-sm">
                        @php($portalUnread = auth('web')->user()->unreadNotifications()->count())
                        <a href="{{ route('portal.notifications') }}" class="text-gray-600 hover:text-gray-900">
                            Notifikasi
                            @if ($portalUnread > 0)
                                <span class="ml-1 inline-flex items-center justify-center rounded-full bg-blue-600 px-1.5 text-xs font-medium text-white">
                                    {{ $portalUnread > 99 ? '99+' : $portalUnread }}
                                </span>
                            @endif
                        </a>
                        <span class="text-gray-500">{{ auth('web')->user()->name }}</span>
                        <form method="POST" action="{{ route('portal.logout') }}">
                            @csrf
                            <button type="submit" class="text-red-600 hover:underline">Keluar</button>
                        </form>
                    </div>
                </div>
            </nav>
        @endif
    @endauth

    <main class="max-w-5xl mx-auto px-4 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
