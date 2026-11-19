@if (session('error'))
    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        {{ session('error') }}
    </div>
@endif
@if (session('status'))
    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700" role="status">
        {{ session('status') }}
    </div>
@endif
@if (! empty($missingConfig))
    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
        <p class="font-semibold">Cloudflare RealtimeKit is not configured yet.</p>
        <p class="mt-1">Add the following values to your <code class="rounded bg-amber-100 px-1">.env</code> file, then run <code class="rounded bg-amber-100 px-1">php artisan config:clear</code>:</p>
        <ul class="mt-2 list-inside list-disc font-mono text-xs">
            @foreach ($missingConfig as $key)
                <li>{{ $key }}</li>
            @endforeach
        </ul>
    </div>
@endif
