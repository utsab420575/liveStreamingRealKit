<?php

namespace App\Services;

use App\Exceptions\RealtimeKitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the Cloudflare RealtimeKit REST API.
 *
 * Docs: https://developers.cloudflare.com/api/resources/realtime_kit/subresources/meetings/
 * Base: https://api.cloudflare.com/client/v4/accounts/{account_id}/realtime/kit/{app_id}
 */
class RealtimeKitService
{
    /** Keys that must be configured before any API call is attempted. */
    private const REQUIRED_CONFIG = [
        'account_id' => 'CLOUDFLARE_ACCOUNT_ID',
        'app_id' => 'REALTIMEKIT_APP_ID',
        'api_token' => 'CLOUDFLARE_API_TOKEN',
        'host_preset' => 'REALTIMEKIT_HOST_PRESET_NAME',
        'participant_preset' => 'REALTIMEKIT_PARTICIPANT_PRESET_NAME',
    ];

    /** @return list<string> Names of missing .env variables. */
    public function missingConfiguration(): array
    {
        $missing = [];
        foreach (self::REQUIRED_CONFIG as $key => $envName) {
            if (blank(config("services.realtimekit.{$key}"))) {
                $missing[] = $envName;
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    /**
     * Create a reusable RealtimeKit Meeting.
     *
     * @return string The Cloudflare meeting ID (data.id).
     */
    public function createMeeting(string $title): string
    {
        Log::info('RealtimeKit: Initiating meeting creation on Cloudflare', ['title' => $title]);

        // No automatic retries: a retried POST could create duplicate meetings.
        $data = $this->send('post', '/meetings', ['title' => $title], 'create meeting');

        $id = $data['id'] ?? null;
        if (! is_string($id) || $id === '') {
            Log::error('RealtimeKit create meeting: response missing data.id', ['response' => $data]);
            throw new RealtimeKitException('Cloudflare did not return a meeting ID. Please try again.');
        }

        Log::info('RealtimeKit: Meeting created successfully on Cloudflare', [
            'cloudflare_meeting_id' => $id,
            'title' => $title,
        ]);

        return $id;
    }

    /**
     * Add a participant to a meeting and obtain an individual auth token.
     *
     * @return array{participant_id: string, token: string}
     */
    public function addParticipant(string $meetingId, string $name, bool $isHost): array
    {
        $preset = (string) config($isHost ? 'services.realtimekit.host_preset' : 'services.realtimekit.participant_preset');
        $ttl = max(5, (int) config('services.realtimekit.token_ttl_minutes', 60));

        Log::info('RealtimeKit: Adding participant to meeting', [
            'meeting_id' => $meetingId,
            'participant_name' => $name,
            'is_host' => $isHost,
            'preset_name' => $preset,
        ]);

        $data = $this->send('post', '/meetings/'.rawurlencode($meetingId).'/participants', [
            'name' => $name,
            'preset_name' => $preset,
            'custom_participant_id' => (string) Str::uuid(),
            'token' => [
                // RFC 3339 timestamp with timezone, e.g. 2026-11-19T13:00:00+00:00
                'expires_on' => now()->utc()->addMinutes($ttl)->toRfc3339String(),
            ],
        ], 'add participant');

        $participantId = $data['id'] ?? null;
        $token = $data['token'] ?? null;

        if (! is_string($participantId) || $participantId === '' || ! is_string($token) || $token === '') {
            Log::error('RealtimeKit add participant: response missing data.id or data.token', ['response' => $data]);
            throw new RealtimeKitException('Cloudflare did not return a participant token. Please try again.');
        }

        Log::info('RealtimeKit: Participant added successfully', [
            'meeting_id' => $meetingId,
            'participant_id' => $participantId,
            'is_host' => $isHost,
        ]);

        return ['participant_id' => $participantId, 'token' => $token];
    }

    /**
     * Mark a meeting INACTIVE. Users will not be able to join an INACTIVE meeting.
     */
    public function disableMeeting(string $meetingId): void
    {
        Log::info('RealtimeKit: Disabling meeting on Cloudflare', ['meeting_id' => $meetingId]);

        $data = $this->send('patch', '/meetings/'.rawurlencode($meetingId), ['status' => 'INACTIVE'], 'disable meeting');

        if (isset($data['status']) && strtoupper((string) $data['status']) !== 'INACTIVE') {
            Log::error('RealtimeKit disable meeting: status not INACTIVE after update', ['status' => $data['status']]);
            throw new RealtimeKitException('Cloudflare did not confirm that the meeting was disabled.');
        }

        Log::info('RealtimeKit: Meeting disabled successfully on Cloudflare', ['meeting_id' => $meetingId]);
    }

    /**
     * Perform a request and return the validated `data` object.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload, string $operation): array
    {
        $this->ensureConfigured();

        $cfg = config('services.realtimekit');
        $fullUrl = rtrim($cfg['base_url'] ?? '', '/')
            .'/accounts/'.rawurlencode($cfg['account_id'] ?? '')
            .'/realtime/kit/'.rawurlencode($cfg['app_id'] ?? '')
            .$path;

        try {
            /** @var Response $response */
            $response = $this->client()->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            Log::error("RealtimeKit {$operation}: Connection failed", [
                'operation' => $operation,
                'endpoint' => $fullUrl,
                'error' => $this->sanitize($e->getMessage()),
            ]);
            throw new RealtimeKitException(
                'Could not reach Cloudflare RealtimeKit. Check your internet connection and try again.',
                RealtimeKitException::NETWORK,
            );
        }

        $json = $response->json();
        $success = is_array($json) && ($json['success'] ?? false) === true;

        if (! $response->successful() || ! $success) {
            $this->throwForFailure($response, is_array($json) ? $json : [], $operation, $fullUrl, $payload);
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            Log::error("RealtimeKit {$operation}: Response missing data object", [
                'status' => $response->status(),
                'endpoint' => $fullUrl,
                'response_body' => $this->sanitize($response->body()),
            ]);
            throw new RealtimeKitException('Unexpected response from Cloudflare RealtimeKit.');
        }

        return $data;
    }

    /** @param array<string, mixed> $json */
    private function throwForFailure(Response $response, array $json, string $operation, string $url = '', array $payload = []): never
    {
        $status = $response->status();
        $apiMessage = $this->sanitize($this->extractErrorMessage($json));
        $cfg = config('services.realtimekit', []);
        $token = (string) ($cfg['api_token'] ?? '');

        $maskedToken = $token !== ''
            ? substr($token, 0, 7).'...'.substr($token, -6).' (length: '.strlen($token).')'
            : 'NOT SET';

        $diagnosticContext = [
            'operation' => $operation,
            'endpoint' => $url,
            'status' => $status,
            'error_message' => $apiMessage,
            'cloudflare_raw_errors' => $json['errors'] ?? ($json ?: $this->sanitize($response->body())),
            'configured_account_id' => $cfg['account_id'] ?? 'NOT SET',
            'configured_app_id' => $cfg['app_id'] ?? 'NOT SET',
            'configured_token_preview' => $maskedToken,
            'payload' => $payload,
        ];

        if ($status === 401 || $status === 403) {
            $diagnosticContext['troubleshooting'] = 'Cloudflare rejected the API credentials. Check that CLOUDFLARE_API_TOKEN has "Realtime" or "Realtime Admin" Account permissions, and that CLOUDFLARE_ACCOUNT_ID and REALTIMEKIT_APP_ID match the Cloudflare Dashboard.';
            Log::error("RealtimeKit {$operation} failed: Authentication / Permission error (HTTP {$status})", $diagnosticContext);

            throw new RealtimeKitException(
                'Cloudflare rejected the API credentials. Check CLOUDFLARE_ACCOUNT_ID, REALTIMEKIT_APP_ID and CLOUDFLARE_API_TOKEN (the token needs the "Realtime" or "Realtime Admin" permission).',
                RealtimeKitException::AUTH_FAILED,
                $status,
            );
        }

        $lower = strtolower($apiMessage);

        if (str_contains($lower, 'preset')) {
            $diagnosticContext['troubleshooting'] = 'Preset not found. Check that REALTIMEKIT_HOST_PRESET_NAME and REALTIMEKIT_PARTICIPANT_PRESET_NAME exactly match presets created in your RealtimeKit dashboard.';
            Log::error("RealtimeKit {$operation} failed: Invalid Preset", $diagnosticContext);

            throw new RealtimeKitException(
                'The configured RealtimeKit preset was not found. Make sure REALTIMEKIT_HOST_PRESET_NAME and REALTIMEKIT_PARTICIPANT_PRESET_NAME exactly match presets in your RealtimeKit app.',
                RealtimeKitException::PRESET,
                $status,
            );
        }

        if (str_contains($lower, 'inactive')) {
            Log::warning("RealtimeKit {$operation} failed: Meeting inactive", $diagnosticContext);
            throw new RealtimeKitException('This meeting is no longer active.', RealtimeKitException::MEETING_INACTIVE, $status);
        }

        if ($status === 429 || str_contains($lower, 'limit') || str_contains($lower, 'capacity') || str_contains($lower, 'maximum')) {
            Log::warning("RealtimeKit {$operation} failed: Rate/Capacity limit", $diagnosticContext);
            throw new RealtimeKitException(
                'The meeting has reached a participant or usage limit set by Cloudflare RealtimeKit. Please try again later.',
                RealtimeKitException::LIMIT,
                $status,
            );
        }

        if ($status === 404) {
            $diagnosticContext['troubleshooting'] = 'Resource not found (HTTP 404). Check that REALTIMEKIT_APP_ID exists under CLOUDFLARE_ACCOUNT_ID.';
            Log::error("RealtimeKit {$operation} failed: Resource Not Found (HTTP 404)", $diagnosticContext);

            throw new RealtimeKitException(
                'Cloudflare could not find the requested RealtimeKit resource. Check REALTIMEKIT_APP_ID and CLOUDFLARE_ACCOUNT_ID.',
                RealtimeKitException::API,
                $status,
            );
        }

        Log::error("RealtimeKit {$operation} failed: API request error", $diagnosticContext);

        throw new RealtimeKitException(
            'Cloudflare RealtimeKit request failed'.($apiMessage !== '' ? ': '.$apiMessage : '.'),
            RealtimeKitException::API,
            $status,
        );
    }

    /** Cloudflare may return {errors:[{message}]} or {error:{message}} or {message}. */
    private function extractErrorMessage(array $json): string
    {
        if (isset($json['errors'][0]['message'])) {
            return (string) $json['errors'][0]['message'];
        }
        if (isset($json['error']['message'])) {
            return (string) $json['error']['message'];
        }
        if (isset($json['error']) && is_string($json['error'])) {
            return $json['error'];
        }

        return isset($json['message']) && is_string($json['message']) ? $json['message'] : '';
    }

    /** Strip anything resembling a token/JWT/secret and cap the length. */
    private function sanitize(string $message): string
    {
        $secret = (string) config('services.realtimekit.api_token');
        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }
        $message = preg_replace('/eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', '[redacted-jwt]', $message) ?? '';
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? '';

        return Str::limit(trim($message), 300);
    }

    private function ensureConfigured(): void
    {
        $missing = $this->missingConfiguration();
        if ($missing !== []) {
            Log::error('RealtimeKit: Missing required configuration keys in .env', [
                'missing_keys' => $missing,
            ]);
            throw new RealtimeKitException(
                'Cloudflare RealtimeKit is not configured. Add these values to your .env file: '.implode(', ', $missing).'. Then run "php artisan config:clear".',
                RealtimeKitException::NOT_CONFIGURED,
            );
        }
    }

    private function client(): PendingRequest
    {
        $cfg = config('services.realtimekit');
        $baseUrl = rtrim($cfg['base_url'], '/')
            .'/accounts/'.rawurlencode($cfg['account_id'])
            .'/realtime/kit/'.rawurlencode($cfg['app_id']);

        return Http::baseUrl($baseUrl)
            ->withToken($cfg['api_token'])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(max(5, (int) $cfg['timeout']));
    }
}
