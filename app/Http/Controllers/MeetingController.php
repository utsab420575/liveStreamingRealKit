<?php

namespace App\Http\Controllers;

use App\Exceptions\RealtimeKitException;
use App\Models\Meeting;
use App\Services\RealtimeKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class MeetingController extends Controller
{
    public function __construct(private readonly RealtimeKitService $realtimeKit) {}

    public function home(): View
    {
        return view('home');
    }

    public function create(): View
    {
        return view('meetings.create', [
            'missingConfig' => $this->realtimeKit->missingConfiguration(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:150'],
            'host_name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $title = trim($validated['title']);
        $hostName = trim($validated['host_name']);

        try {
            $cloudflareMeetingId = $this->realtimeKit->createMeeting($title);
        } catch (RealtimeKitException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $hostSecret = Str::random(64); // CSPRNG-backed

        try {
            $meeting = new Meeting([
                'title' => $title,
                'meeting_code' => Meeting::generateUniqueCode(),
                'realtimekit_meeting_id' => $cloudflareMeetingId,
                'host_name' => $hostName,
                'status' => Meeting::STATUS_ACTIVE,
            ]);
            $meeting->setHostSecret($hostSecret);
            $meeting->save();
        } catch (Throwable $e) {
            $this->handleOrphanedCloudflareMeeting($cloudflareMeetingId, $e);

            return back()->withInput()->with('error', 'The meeting could not be saved. Please try again.');
        }

        // Raw secret lives only in this browser's server-side session.
        $request->session()->put($meeting->hostSessionKey(), $hostSecret);
        $request->session()->put('display_name', $hostName);

        return redirect()
            ->route('meetings.show', $meeting)
            ->with('created', true);
    }

    public function join(): View
    {
        return view('meetings.join');
    }

    public function resolve(Request $request): RedirectResponse
    {
        $request->merge([
            'meeting_code' => strtoupper(preg_replace('/[\s\-]+/', '', (string) $request->input('meeting_code'))),
        ]);

        $validated = $request->validate([
            'meeting_code' => ['required', 'string', 'regex:'.Meeting::CODE_PATTERN],
            'name' => ['required', 'string', 'min:1', 'max:100'],
        ], [
            'meeting_code.regex' => 'Meeting codes are 6–10 letters and numbers.',
        ]);

        $meeting = Meeting::where('meeting_code', $validated['meeting_code'])->first();

        if (! $meeting) {
            return back()->withInput()->withErrors(['meeting_code' => 'No meeting was found with that code.']);
        }

        // Remember the name for prefilling (session, never the URL).
        $request->session()->put('display_name', trim($validated['name']));

        return redirect()->route('meetings.show', $meeting);
    }

    public function show(Request $request, Meeting $meeting): View
    {
        $isHost = $meeting->isHostSession();

        return view('meetings.show', [
            'meeting' => $meeting,
            'isHost' => $isHost,
            'displayName' => old('name', $request->session()->get('display_name', $isHost ? $meeting->host_name : '')),
            'shareUrl' => route('meetings.show', $meeting),
            'justCreated' => (bool) $request->session()->get('created', false),
            'missingConfig' => $this->realtimeKit->missingConfiguration(),
        ]);
    }

    public function disable(Request $request, Meeting $meeting): JsonResponse|RedirectResponse
    {
        if (! $meeting->isHostSession()) {
            return $this->respond($request, 403, 'Only the host who created this meeting can disable it.');
        }

        if (! $meeting->isActive()) {
            return $this->respond($request, 200, 'This meeting is already disabled.', ['status' => $meeting->status]);
        }

        try {
            $this->realtimeKit->disableMeeting($meeting->realtimekit_meeting_id);
        } catch (RealtimeKitException $e) {
            // Cloudflare reporting it is already inactive is an acceptable end state.
            if ($e->reason !== RealtimeKitException::MEETING_INACTIVE) {
                return $this->respond($request, 502, 'The meeting could not be disabled: '.$e->getMessage());
            }
        }

        // Only after Cloudflare confirmed the change.
        $meeting->status = Meeting::STATUS_DISABLED;
        $meeting->save();

        return $this->respond($request, 200, 'The meeting has been disabled. No one can join it anymore.', ['status' => $meeting->status]);
    }

    private function respond(Request $request, int $status, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $extra, $status);
        }

        return redirect()
            ->route('meetings.show', $request->route('meeting'))
            ->with($status >= 400 ? 'error' : 'status', $message);
    }

    /**
     * Cloudflare created a meeting but the local insert failed. RealtimeKit
     * has no "delete meeting" endpoint, so mark it INACTIVE and log its ID
     * (an ID, not a secret) for manual cleanup.
     */
    private function handleOrphanedCloudflareMeeting(string $cloudflareMeetingId, Throwable $cause): void
    {
        $cleanedUp = false;
        try {
            $this->realtimeKit->disableMeeting($cloudflareMeetingId);
            $cleanedUp = true;
        } catch (Throwable) {
            // ignored; logged below
        }

        Log::error('Local meeting insert failed after Cloudflare meeting was created', [
            'realtimekit_meeting_id' => $cloudflareMeetingId,
            'marked_inactive' => $cleanedUp,
            'error' => class_basename($cause),
        ]);
    }
}
