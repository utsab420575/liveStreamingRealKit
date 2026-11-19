<?php

use App\Services\RealtimeKitService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('realtimekit:check', function (RealtimeKitService $service) {
    $this->info('=============================================');
    $this->info('  Cloudflare RealtimeKit Diagnostic Check    ');
    $this->info('=============================================');

    $cfg = config('services.realtimekit', []);
    $accountId = $cfg['account_id'] ?? null;
    $appId = $cfg['app_id'] ?? null;
    $token = $cfg['api_token'] ?? null;
    $hostPreset = $cfg['host_preset'] ?? null;
    $partPreset = $cfg['participant_preset'] ?? null;

    $this->table(
        ['Variable', 'Status', 'Configured Value'],
        [
            ['CLOUDFLARE_ACCOUNT_ID', filled($accountId) ? 'OK' : 'MISSING', filled($accountId) ? substr($accountId, 0, 8).'...' : '—'],
            ['REALTIMEKIT_APP_ID', filled($appId) ? 'OK' : 'MISSING', filled($appId) ? substr($appId, 0, 8).'...' : '—'],
            ['CLOUDFLARE_API_TOKEN', filled($token) ? 'OK' : 'MISSING', filled($token) ? substr($token, 0, 7).'...'.substr($token, -4).' (len: '.strlen($token).')' : '—'],
            ['REALTIMEKIT_HOST_PRESET_NAME', filled($hostPreset) ? 'OK' : 'MISSING', $hostPreset ?? '—'],
            ['REALTIMEKIT_PARTICIPANT_PRESET_NAME', filled($partPreset) ? 'OK' : 'MISSING', $partPreset ?? '—'],
        ]
    );

    $missing = $service->missingConfiguration();
    if (! empty($missing)) {
        $this->error('Missing required environment variables: '.implode(', ', $missing));
        $this->comment('Add them to your .env file and run "php artisan config:clear".');
        return 1;
    }

    $baseUrl = rtrim($cfg['base_url'] ?? 'https://api.cloudflare.com/client/v4', '/');
    $url = "{$baseUrl}/accounts/{$accountId}/realtime/kit/{$appId}";

    $this->info("\nTesting Cloudflare API endpoint: {$url}");

    try {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->get($url);

        $status = $response->status();
        $json = $response->json() ?? [];

        if ($response->successful()) {
            $this->info("✓ Connection successful! (HTTP {$status})");
            $this->line(json_encode($json, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->error("✗ Cloudflare rejected the request (HTTP {$status})");
        $errors = $json['errors'] ?? ($json['error'] ?? $json);
        $this->line('Cloudflare response: '.json_encode($errors, JSON_PRETTY_PRINT));

        if ($status === 401 || $status === 403) {
            $this->warn("\nAuthentication/Permission Error Checklist:");
            $this->line('1. Check CLOUDFLARE_API_TOKEN permissions:');
            $this->line('   Must have Account > Realtime (or RealtimeKit) > Edit permission.');
            $this->line('2. Check Account Scope:');
            $this->line('   The token must be scoped to account "'.$accountId.'".');
            $this->line('3. Check IP restrictions:');
            $this->line('   If Client IP filtering is set on the token, ensure your current IP is whitelisted.');
            $this->line('4. Verify the token has not expired.');
        } elseif ($status === 404) {
            $this->warn("\nResource Not Found Checklist:");
            $this->line('1. Check REALTIMEKIT_APP_ID: Ensure app ID exists under account '.$accountId);
            $this->line('2. Check CLOUDFLARE_ACCOUNT_ID: Verify account ID in Cloudflare dashboard URL.');
        }

        return 1;
    } catch (\Throwable $e) {
        $this->error('Connection Exception: '.$e->getMessage());
        return 1;
    }
})->purpose('Diagnose Cloudflare RealtimeKit connection and credentials');
