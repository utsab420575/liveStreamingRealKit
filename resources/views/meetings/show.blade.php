@extends('layouts.app')

@section('title', $meeting->title)

@push('head')
    @vite(['resources/js/meeting.js'])
@endpush

@section('content')
<div id="meeting-app"
     data-token-url="{{ route('meetings.token', $meeting) }}"
     data-disable-url="{{ route('meetings.disable', $meeting) }}"
     data-is-host="{{ $isHost ? 'true' : 'false' }}"
     data-active="{{ $meeting->isActive() ? 'true' : 'false' }}">

    {{-- ================= Lobby ================= --}}
    <section id="lobby" class="mx-auto max-w-5xl px-4 py-10">
        @include('partials.alerts')

        <div class="grid gap-6 md:grid-cols-5">
            {{-- Meeting info --}}
            <div class="space-y-6 md:col-span-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Meeting</p>
                            <h1 class="mt-1 text-2xl font-bold text-slate-900 break-words">{{ $meeting->title }}</h1>
                            <p class="mt-1 text-sm text-slate-500">Hosted by {{ $meeting->host_name }}</p>
                        </div>
                        @if ($meeting->isActive())
                            <span data-status-badge class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Active
                            </span>
                        @else
                            <span data-status-badge class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                                <span class="h-2 w-2 rounded-full bg-slate-400"></span> Disabled
                            </span>
                        @endif
                    </div>

                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Meeting code</dt>
                            <dd class="mt-1 font-mono text-xl font-semibold tracking-widest text-slate-900">{{ $meeting->meeting_code }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-slate-500">Your role</dt>
                            <dd class="mt-1 text-sm font-medium text-slate-700">{{ $isHost ? 'Host' : 'Participant' }}</dd>
                        </div>
                    </dl>

                    @if ($meeting->isActive())
                        <div class="mt-6">
                            <label for="share-url" class="text-xs font-medium text-slate-500">Shareable link</label>
                            <div class="mt-1 flex gap-2">
                                <input id="share-url" type="text" readonly value="{{ $shareUrl }}"
                                       class="block w-full min-w-0 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-700">
                                <button type="button" data-copy-target="share-url"
                                        class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    Copy link
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">Anyone with this link or code can join while the meeting is active.</p>
                        </div>
                    @endif
                </div>

                @if ($justCreated)
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-800">
                        <p class="font-semibold">Your meeting is ready.</p>
                        <p class="mt-1">Share the link or code above with participants, then join below. This browser is recognised as the host; if you clear its cookies you will lose the ability to disable the meeting.</p>
                    </div>
                @endif
            </div>

            {{-- Join panel --}}
            <div class="md:col-span-2">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    @if ($meeting->isActive())
                        <h2 class="text-lg font-semibold text-slate-900">Join the meeting</h2>
                        <p class="mt-1 text-sm text-slate-500">You can check your camera and microphone before entering.</p>

                        <form id="join-form" class="mt-5 space-y-4" novalidate>
                            <div>
                                <label for="name" class="block text-sm font-medium text-slate-700">Your name</label>
                                <input id="name" name="name" type="text" required maxlength="100" value="{{ $displayName }}"
                                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                       autocomplete="name" placeholder="Your display name">
                            </div>
                            <p id="join-error" class="hidden rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"></p>
                            <button id="join-button" type="submit" @disabled(! empty($missingConfig))
                                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
                                <span data-label>Join meeting</span>
                            </button>
                        </form>

                        @if ($isHost)
                            <div class="mt-6 border-t border-slate-100 pt-5">
                                <p class="text-sm font-medium text-slate-700">Host controls</p>
                                <p class="mt-1 text-xs text-slate-500">Disabling stops anyone from joining this meeting again. This cannot be undone.</p>
                                <button type="button" data-disable-button
                                        class="mt-3 w-full rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:opacity-60">
                                    Disable meeting
                                </button>
                            </div>
                        @endif
                    @else
                        <h2 class="text-lg font-semibold text-slate-900">Meeting disabled</h2>
                        <p class="mt-2 text-sm text-slate-600">The host has disabled this meeting. It can no longer be joined.</p>
                        <a href="{{ route('meetings.create') }}" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create a new meeting</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ================= Live meeting ================= --}}
    <section id="live" class="hidden" aria-label="Live meeting">
        <div class="flex items-center justify-between gap-3 border-b border-slate-800 bg-slate-900 px-4 py-2 text-white">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ $meeting->title }}</p>
                <p class="font-mono text-xs text-slate-400">{{ $meeting->meeting_code }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" data-copy-target="share-url" class="hidden rounded-lg px-3 py-1.5 text-xs font-medium text-slate-300 hover:bg-slate-800 sm:inline-flex">Copy link</button>
                <button type="button" id="leave-button" class="rounded-lg bg-slate-700 px-3 py-1.5 text-xs font-semibold hover:bg-slate-600">Leave meeting</button>
                @if ($isHost)
                    <button type="button" data-disable-button class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold hover:bg-red-700 disabled:opacity-60">Disable meeting</button>
                @endif
            </div>
        </div>

        <div id="network-banner" class="hidden bg-amber-500 px-4 py-1.5 text-center text-xs font-medium text-white" role="status">
            You appear to be offline. Trying to reconnect…
        </div>
        <div id="media-banner" class="hidden bg-red-600 px-4 py-1.5 text-center text-xs font-medium text-white" role="alert"></div>

        <div class="relative h-[calc(100dvh-7.5rem)] min-h-[420px] bg-slate-950">
            <div id="live-loading" class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 text-slate-300">
                <span class="h-10 w-10 animate-spin rounded-full border-4 border-slate-700 border-t-indigo-500"></span>
                <p class="text-sm">Connecting to the meeting…</p>
            </div>
            <rtk-meeting id="rtk-meeting" show-setup-screen="true" class="block h-full w-full"></rtk-meeting>
        </div>
    </section>

    {{-- ================= After leaving / errors ================= --}}
    <section id="ended" class="mx-auto hidden max-w-lg px-4 py-20 text-center">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <h2 id="ended-title" class="text-xl font-semibold text-slate-900">You left the meeting</h2>
            <p id="ended-message" class="mt-2 text-sm text-slate-600">The meeting is still available. You can rejoin with the same link.</p>
            <div class="mt-6 flex flex-col justify-center gap-2 sm:flex-row">
                <button type="button" id="rejoin-button" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Rejoin</button>
                <a href="{{ route('home') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back to home</a>
            </div>
        </div>
    </section>
</div>
@endsection
