<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeetingFlowTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.cloudflare.com/client/v4/accounts/acc123/realtime/kit/app456';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.realtimekit.account_id' => 'acc123',
            'services.realtimekit.app_id' => 'app456',
            'services.realtimekit.api_token' => 'secret-api-token',
            'services.realtimekit.host_preset' => 'host_preset',
            'services.realtimekit.participant_preset' => 'participant_preset',
        ]);

        Http::preventStrayRequests();
    }

    private function fakeCloudflare(): void
    {
        $counter = 0;
        Http::fake([
            self::BASE.'/meetings' => Http::response(['success' => true, 'data' => ['id' => 'cf-meeting-uuid', 'status' => 'ACTIVE']]),
            self::BASE.'/meetings/cf-meeting-uuid/participants' => function () use (&$counter) {
                $counter++;

                return Http::response(['success' => true, 'data' => ['id' => "p-{$counter}", 'token' => "jwt-token-{$counter}"]]);
            },
            self::BASE.'/meetings/cf-meeting-uuid' => Http::response(['success' => true, 'data' => ['id' => 'cf-meeting-uuid', 'status' => 'INACTIVE']]),
        ]);
    }

    private function createMeeting(): Meeting
    {
        $this->post(route('meetings.store'), ['title' => 'Team Sync', 'host_name' => 'Alex'])->assertRedirect();

        return Meeting::firstOrFail();
    }

    public function test_pages_load(): void
    {
        $this->get('/')->assertOk()->assertSee('Create Meeting')->assertSee('Join Meeting');
        $this->get(route('meetings.create'))->assertOk();
        $this->get(route('meetings.join'))->assertOk();
    }

    public function test_missing_credentials_show_setup_message_and_make_no_request(): void
    {
        config(['services.realtimekit.api_token' => null]);

        $this->get(route('meetings.create'))->assertOk()->assertSee('CLOUDFLARE_API_TOKEN');

        $this->post(route('meetings.store'), ['title' => 'Team Sync', 'host_name' => 'Alex'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not configured'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_host_creates_meeting(): void
    {
        $this->fakeCloudflare();

        $meeting = $this->createMeeting();

        $this->assertSame('cf-meeting-uuid', $meeting->realtimekit_meeting_id);
        $this->assertMatchesRegularExpression(Meeting::CODE_PATTERN, $meeting->meeting_code);
        $this->assertSame('active', $meeting->status);
        $this->assertNotEmpty($meeting->host_secret_hash);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === self::BASE.'/meetings'
            && $r['title'] === 'Team Sync'
            && $r->hasHeader('Authorization', 'Bearer secret-api-token'));

        $this->get(route('meetings.show', $meeting))
            ->assertOk()
            ->assertSee($meeting->meeting_code)
            ->assertSee('Disable meeting')
            ->assertDontSee('cf-meeting-uuid')
            ->assertDontSee('secret-api-token');
    }

    public function test_cloudflare_create_failure_is_reported(): void
    {
        Http::fake([self::BASE.'/meetings' => Http::response(['success' => false, 'errors' => [['message' => 'Unauthorized']]], 401)]);

        $this->post(route('meetings.store'), ['title' => 'Team Sync', 'host_name' => 'Alex'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'rejected the API credentials'));

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_host_and_multiple_participants_receive_distinct_tokens_with_correct_presets(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();

        // Host (same session that created the meeting)
        $this->postJson(route('meetings.token', $meeting), ['name' => 'Alex'])
            ->assertOk()
            ->assertJson(['token' => 'jwt-token-1', 'role' => 'host']);

        // Five participants from fresh sessions — no two-person limit.
        for ($i = 2; $i <= 6; $i++) {
            $this->flushSession();
            $this->postJson(route('meetings.token', $meeting), ['name' => "Guest {$i}"])
                ->assertOk()
                ->assertJson(['token' => "jwt-token-{$i}", 'role' => 'participant']);
        }

        $participantRequests = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/participants'))->values();
        $this->assertCount(6, $participantRequests);

        $ids = [];
        foreach ($participantRequests as $index => [$request]) {
            $this->assertSame($index === 0 ? 'host_preset' : 'participant_preset', $request['preset_name']);
            $this->assertTrue(\Illuminate\Support\Str::isUuid($request['custom_participant_id']));
            $expires = \Carbon\Carbon::parse($request['token']['expires_on']);
            $this->assertTrue($expires->between(now()->addMinutes(55), now()->addMinutes(65)));
            $ids[] = $request['custom_participant_id'];
        }
        $this->assertCount(6, array_unique($ids));
    }

    public function test_join_by_code_resolves_and_rejects_invalid_codes(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();
        $this->flushSession();

        $this->post(route('meetings.resolve'), ['meeting_code' => strtolower($meeting->meeting_code), 'name' => 'Sam'])
            ->assertRedirect(route('meetings.show', $meeting));

        $this->post(route('meetings.resolve'), ['meeting_code' => 'ZZZZZZZZ', 'name' => 'Sam'])
            ->assertSessionHasErrors('meeting_code');

        $this->post(route('meetings.resolve'), ['meeting_code' => 'bad!', 'name' => 'Sam'])
            ->assertSessionHasErrors('meeting_code');

        $this->get('/meetings/NOPE9999')->assertNotFound();
    }

    public function test_participant_cannot_disable_meeting(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();
        $this->flushSession();

        $this->get(route('meetings.show', $meeting))->assertOk()->assertDontSee('Disable meeting');
        $this->postJson(route('meetings.disable', $meeting))->assertForbidden();

        $this->assertSame('active', $meeting->fresh()->status);
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'PATCH');
    }

    public function test_host_disables_meeting_and_tokens_are_rejected(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();

        $this->postJson(route('meetings.disable', $meeting))->assertOk()->assertJson(['status' => 'disabled']);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && $r->url() === self::BASE.'/meetings/cf-meeting-uuid'
            && $r['status'] === 'INACTIVE');

        $this->assertSame('disabled', $meeting->fresh()->status);

        $this->postJson(route('meetings.token', $meeting), ['name' => 'Late'])->assertStatus(410);
        $this->get(route('meetings.show', $meeting))->assertOk()->assertSee('Meeting disabled');
    }

    public function test_failed_cloudflare_disable_does_not_mark_disabled(): void
    {
        Http::fake([
            self::BASE.'/meetings' => Http::response(['success' => true, 'data' => ['id' => 'cf-meeting-uuid']]),
            self::BASE.'/meetings/cf-meeting-uuid' => Http::response(['success' => false, 'errors' => [['message' => 'Internal error']]], 500),
        ]);
        $meeting = $this->createMeeting();

        $this->postJson(route('meetings.disable', $meeting))->assertStatus(502);
        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_preset_error_is_friendly(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();

        // Earlier fakes are matched first; start from a fresh fake factory.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::BASE.'/meetings/cf-meeting-uuid/participants' => Http::response(['success' => false, 'errors' => [['message' => 'Preset not found']]], 404),
        ]);

        $this->postJson(route('meetings.token', $meeting), ['name' => 'Alex'])
            ->assertStatus(502)
            ->assertJson(['reason' => 'preset']);
    }

    public function test_token_request_requires_valid_name_and_csrf_is_enforced_in_web_group(): void
    {
        $this->fakeCloudflare();
        $meeting = $this->createMeeting();

        $this->postJson(route('meetings.token', $meeting), ['name' => ''])->assertUnprocessable();
        $this->postJson(route('meetings.token', $meeting), ['name' => str_repeat('a', 101)])->assertUnprocessable();
    }
}
