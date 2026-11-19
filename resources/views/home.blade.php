@extends('layouts.app')

@section('content')
    <section class="mx-auto flex max-w-3xl flex-col items-center px-4 py-20 text-center sm:py-28">
        <span class="mb-4 rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700">Video meetings powered by Cloudflare RealtimeKit</span>
        <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">Simple Live Meeting</h1>
        <p class="mt-4 max-w-xl text-lg text-slate-600">
            Start a video meeting in seconds. Share the link or code, and anyone with it can join from their browser — no account needed.
        </p>
        <div class="mt-10 flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
            <a href="{{ route('meetings.create') }}"
               class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Create Meeting
            </a>
            <a href="{{ route('meetings.join') }}"
               class="rounded-xl border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                Join Meeting
            </a>
        </div>
        <p class="mt-10 max-w-md text-xs text-slate-400">
            Meeting links work like invitations: anyone who has the link or code can join.
        </p>
    </section>
@endsection
