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
        // No automatic retries: a retried POST could create duplicate meetings.
        $data = $this->send('post', '/meetings', ['title' => $title], 'create meeting');

        $id = $data['id'] ?? null;
        if (! is_string($id) || $id === '') {
            Log::error('RealtimeKit create meeting: response missing data.id');
            throw new RealtimeKitException('Cloudflare did not return a meeting ID. Please try again.');
        }

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
            Log::error('RealtimeKit add participant: response missing data.id or data.token');
            throw new RealtimeKitException('Cloudflare did not return a participant token. Please try again.');
        }

        return ['participant_id' => $participantId, 'token' => $token];
    }

    /**
     * Mark a meeting INACTIVE. Users will not be able to join an INACTIVE meeting.
     */
    public function disableMeeting(string $meetingId): void
    {
        $data = $this->send('patch', '/meetings/'.rawurlencode($meetingId), ['status' => 'INACTIVE'], 'disable meeting');

        if (isset($data['status']) && strtoupper((string) $data['status']) !== 'INACTIVE') {
            Log::error('RealtimeKit disable meeting: status not INACTIVE after update', ['status' => $data['status']]);
            throw new RealtimeKitException('Cloudflare did not confirm that the meeting was disabled.');
        }
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

        try {
            /** @var Response $response */
            $response = $this->client()->{$method}($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning("RealtimeKit {$operation}: connection failed", ['error' => $this->sanitize($e->getMessage())]);
            throw new RealtimeKitException(
                'Could not reach Cloudflare RealtimeKit. Check your internet connection and try again.',
                RealtimeKitException::NETWORK,
            );
        }

        $json = $response->json();
        $success = is_array($json) && ($json['success'] ?? false) === true;

        if (! $response->successful() || ! $success) {
            $this->throwForFailure($response, is_array($json) ? $json : [], $operation);
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            Log::error("RealtimeKit {$operation}: response missing data object", ['status' => $response->status()]);
            throw new RealtimeKitException('Unexpected response from Cloudflare RealtimeKit.');
        }

        return $data;
    }

    /** @param array<string, mixed> $json */
    private function throwForFailure(Response $response, array $json, string $operation): never
    {
        $status = $response->status();
        $apiMessage = $this->sanitize($this->extractErrorMessage($json));

        // Safe context only: HTTP status and a sanitized error message.
        Log::warning("RealtimeKit {$operation} failed", ['status' => $status, 'error' => $apiMessage]);

        $lower = strtolower($apiMessage);

        if ($status === 401 || $status === 403) {
            throw new RealtimeKitException(
                'Cloudflare rejected the API credentials. Check CLOUDFLARE_ACCOUNT_ID, REALTIMEKIT_APP_ID and CLOUDFLARE_API_TOKEN (the token needs the "Realtime" or "Realtime Admin" permission).',
                RealtimeKitException::AUTH_FAILED,
                $status,
            );
        }

        if (str_contains($lower, 'preset')) {
            throw new RealtimeKitException(
                'The configured RealtimeKit preset was not found. Make sure REALTIMEKIT_HOST_PRESET_NAME and REALTIMEKIT_PARTICIPANT_PRESET_NAME exactly match presets in your RealtimeKit app.',
                RealtimeKitException::PRESET,
                $status,
            );
        }

        if (str_contains($lower, 'inactive')) {
            throw new RealtimeKitException('This meeting is no longer active.', RealtimeKitException::MEETING_INACTIVE, $status);
        }

        if ($status === 429 || str_contains($lower, 'limit') || str_contains($lower, 'capacity') || str_contains($lower, 'maximum')) {
            throw new RealtimeKitException(
                'The meeting has reached a participant or usage limit set by Cloudflare RealtimeKit. Please try again later.',
                RealtimeKitException::LIMIT,
                $status,
            );
        }

        if ($status === 404) {
            throw new RealtimeKitException(
                'Cloudflare could not find the requested RealtimeKit resource. Check REALTIMEKIT_APP_ID and CLOUDFLARE_ACCOUNT_ID.',
                RealtimeKitException::API,
                $status,
            );
        }

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
