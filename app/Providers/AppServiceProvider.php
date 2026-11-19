<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Abuse protection for the two operations that call Cloudflare and
        // create billable resources. These are per-IP request limits, NOT
        // participant caps: any number of people can be in one meeting.
        RateLimiter::for('meeting-create', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('meeting-token', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
