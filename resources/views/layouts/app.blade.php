<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif Simple Live Meeting</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-full bg-slate-50 text-slate-800 antialiased flex flex-col">
    <header class="border-b border-slate-200 bg-white/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold text-slate-900">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2.5l4-2.5v12l-4-2.5V18a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z"/></svg>
                </span>
                Simple Live Meeting
            </a>
            <nav class="flex items-center gap-2 text-sm">
                <a href="{{ route('meetings.join') }}" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-slate-100">Join</a>
                <a href="{{ route('meetings.create') }}" class="rounded-lg bg-indigo-600 px-3 py-2 font-medium text-white hover:bg-indigo-700">New meeting</a>
            </nav>
        </div>
    </header>

    <main class="flex-1">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
