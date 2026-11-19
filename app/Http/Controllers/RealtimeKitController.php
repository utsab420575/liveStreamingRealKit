<?php

namespace App\Http\Controllers;

use App\Exceptions\RealtimeKitException;
use App\Models\Meeting;
use App\Services\RealtimeKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RealtimeKitController extends Controller
{
    public function __construct(private readonly RealtimeKitService $realtimeKit) {}

    /**
     * Issue a fresh, individual RealtimeKit participant token for the person
     * making this request. Every call creates a distinct Cloudflare
     * participant with a unique custom_participant_id; there is no
     * participant cap in this application.
     */
    public function token(Request $request, Meeting $meeting): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        // Always re-check status from the database before every token request.
        $meeting->refresh();
        if (! $meeting->isActive()) {
            Log::warning('RealtimeKitController@token: Cannot issue token, meeting is disabled', [
                'meeting_code' => $meeting->meeting_code,
            ]);

            return response()->json([
                'message' => 'This meeting has been disabled by the host and can no longer be joined.',
                'reason' => 'disabled',
            ], 410);
        }

        $name = trim($validated['name']);
        $isHost = $meeting->isHostSession();

        Log::info('RealtimeKitController@token: Requesting participant token', [
            'meeting_code' => $meeting->meeting_code,
            'realtimekit_meeting_id' => $meeting->realtimekit_meeting_id,
            'name' => $name,
            'is_host' => $isHost,
        ]);

        try {
            // Meeting ID always comes from the DB; preset always from server config.
            $participant = $this->realtimeKit->addParticipant($meeting->realtimekit_meeting_id, $name, $isHost);
        } catch (RealtimeKitException $e) {
            Log::error('RealtimeKitController@token: RealtimeKitException while adding participant', [
                'meeting_code' => $meeting->meeting_code,
                'realtimekit_meeting_id' => $meeting->realtimekit_meeting_id,
                'name' => $name,
                'is_host' => $isHost,
                'error' => $e->getMessage(),
                'reason' => $e->reason,
                'status_code' => $e->getCode(),
            ]);

            $status = match ($e->reason) {
                RealtimeKitException::NOT_CONFIGURED => 503,
                RealtimeKitException::LIMIT => 429,
                RealtimeKitException::MEETING_INACTIVE => 410,
                default => 502,
            };

            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $status);
        }

        $request->session()->put('display_name', $name);

        Log::info('RealtimeKitController@token: Token issued successfully', [
            'meeting_code' => $meeting->meeting_code,
            'participant_id' => $participant['participant_id'],
            'is_host' => $isHost,
        ]);

        return response()
            ->json([
                'token' => $participant['token'],
                'role' => $isHost ? 'host' : 'participant',
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
