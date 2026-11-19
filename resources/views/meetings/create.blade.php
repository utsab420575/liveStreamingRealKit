@extends('layouts.app')

@section('title', 'Create meeting')

@section('content')
    <section class="mx-auto max-w-lg px-4 py-12">
        <h1 class="text-2xl font-bold text-slate-900">Create a meeting</h1>
        <p class="mt-1 text-sm text-slate-500">You'll get a link and code to share with participants.</p>

        <div class="mt-6">@include('partials.alerts')</div>

        <form method="POST" action="{{ route('meetings.store') }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-single-submit>
            @csrf
            <div>
                <label for="title" class="block text-sm font-medium text-slate-700">Meeting title</label>
                <input id="title" name="title" type="text" required minlength="2" maxlength="150" value="{{ old('title') }}"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                       placeholder="Weekly team sync">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="host_name" class="block text-sm font-medium text-slate-700">Your name</label>
                <input id="host_name" name="host_name" type="text" required maxlength="100" value="{{ old('host_name') }}"
                       class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                       placeholder="Alex" autocomplete="name">
                @error('host_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                    @disabled(! empty($missingConfig))>
                Create meeting
            </button>
        </form>
    </section>
@endsection
