@extends('layouts.app')

@section('title', 'Join meeting')

@section('content')
    <section class="mx-auto max-w-lg px-4 py-12">
        <h1 class="text-2xl font-bold text-slate-900">Join a meeting</h1>
        <p class="mt-1 text-sm text-slate-500">Enter the meeting code you were given, or simply open the shared link.</p>

        <div class="mt-6">@include('partials.alerts')</div>

        <form method="POST" action="{{ route('meetings.resolve') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-single-submit>
            @csrf
            <div>
                <label for="meeting_code" class="block text-sm font-medium text-slate-700">Meeting code</label>
                <input id="meeting_code" name="meeting_code" type="text" required maxlength="12" value="{{ old('meeting_code') }}"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 font-mono uppercase tracking-widest shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                       placeholder="ABCD2345" autocomplete="off" autocapitalize="characters">
                @error('meeting_code') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Your name</label>
                <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', session('display_name')) }}"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                       placeholder="Sam" autocomplete="name">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
                Continue
            </button>
        </form>
    </section>
@endsection
