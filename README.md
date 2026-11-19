# Simple Live Meeting

A small video meeting prototype built with Laravel 12, Blade, Tailwind CSS and the Cloudflare RealtimeKit Web Components UI Kit.

A host creates a meeting and shares its link or code. Anyone with the link or code can join and talk by video and audio. The app has no participant limit of its own; the real limit comes from your RealtimeKit app, its presets and your Cloudflare account. The host can disable the meeting.

> This is a testing MVP. It has no user accounts. A meeting link works like a bearer invitation: **anyone who has it can join** while the meeting is active.

---

## 1. Requirements

- PHP 8.2+, Composer, Node 18+ (tested with Node 22)
- MySQL (Laragon's MySQL 8.4 works)
- A Cloudflare account with a **RealtimeKit app**

## 2. Configure `.env`

The database settings already point to Laragon's MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=live_streaming_realkit
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=file
```

Fill in the Cloudflare values **locally**. Never commit them.

```dotenv
CLOUDFLARE_ACCOUNT_ID=
REALTIMEKIT_APP_ID=
CLOUDFLARE_API_TOKEN=

# Must exactly match preset names configured in the RealtimeKit dashboard.
REALTIMEKIT_HOST_PRESET_NAME=
REALTIMEKIT_PARTICIPANT_PRESET_NAME=

# Optional: participant token lifetime in minutes (default 60)
# REALTIMEKIT_TOKEN_TTL_MINUTES=60
```

| Variable | Where to find it |
|---|---|
| `CLOUDFLARE_ACCOUNT_ID` | Cloudflare dashboard → any zone/account overview → *Account ID* |
| `REALTIMEKIT_APP_ID` | Dashboard → Realtime → RealtimeKit → your app |
| `CLOUDFLARE_API_TOKEN` | My Profile → API Tokens → Create token. It needs the **Realtime** or **Realtime Admin** permission. |
| `REALTIMEKIT_*_PRESET_NAME` | The preset names shown in your RealtimeKit app's presets list, copied exactly |

**About presets:** presets control what each person can do, such as publishing audio and video or using host controls. The app never guesses preset names and never accepts them from the browser. If your app has only one suitable preset, you can use the same name for both variables. The host then gets no extra in-call powers, but the app's own **Disable meeting** button still works because it runs on the server.

After editing `.env`, run:

```bash
php artisan config:clear
```

If any value is missing, the Create and Join pages show a setup message listing the missing keys. No request is sent to Cloudflare.

## 3. Install and run

```bash
composer install        # already done
php artisan migrate
npm install
npm run build           # or `npm run dev` while developing
php artisan serve       # http://localhost:8000
```

> **Camera and microphone need a secure context.** `http://localhost` and `http://127.0.0.1` count as secure. A Laragon virtual host such as `http://livestreamingrealkit.test` does **not**, so the browser will block the camera and microphone there. Use `php artisan serve` and `http://localhost:8000`, or turn on SSL in Laragon (Menu → Apache/Nginx → SSL → Enabled) and use `https://…test`.
>
> To test from a second device (a phone, or another PC on your network), use an HTTPS tunnel such as `cloudflared tunnel --url http://localhost:8000`. A plain `http://192.168.x.x` address will not get camera or microphone access.

## 4. How it works

```
Host                         Laravel                         Cloudflare RealtimeKit
 │ create (title, name) ───► │ POST /meetings ─────────────► │ new reusable Meeting (data.id)
 │                           │ save row in `meetings`,       │
 │ ◄── link + code ───────── │ host secret hash → DB, raw → session
 │ join ───────────────────► │ POST /meetings/{id}/participants (host preset, uuid, 1h expiry)
 │ ◄── participant token ─── │ ◄──────────────────── data.token
 │ RealtimeKitClient.init({authToken}) → <rtk-meeting>.meeting = meeting
Participant(s): the same flow, each gets their own token with the participant preset.
```

- **One table:** `meetings` (title, meeting_code, realtimekit_meeting_id, host_name, host_secret_hash, status).
- **No participant table:** each join creates a new Cloudflare participant and an individual token. Tokens are issued again on every join and expire after about an hour.
- **Sessions:** RealtimeKit starts a session when the first person joins and ends it after the last person leaves. The same link works for later sessions until the host disables the meeting.
- **Host identity:** creating a meeting generates a random secret. Only its hash goes in the database; the raw secret stays in that browser's server-side session. Only that browser can disable the meeting. If the session is lost (cookies cleared, or the session expires after `SESSION_LIFETIME` minutes), host control is lost; there is no recovery in this MVP.
- **Disable:** `PATCH /meetings/{id}` with `{"status":"INACTIVE"}`. The local row becomes `disabled` only after Cloudflare confirms. After that, Laravel refuses every token request (HTTP 410).

### Key files

| File | Purpose |
|---|---|
| `app/Services/RealtimeKitService.php` | All Cloudflare API calls, error mapping and log sanitizing |
| `app/Http/Controllers/MeetingController.php` | Pages, create, resolve code, disable |
| `app/Http/Controllers/RealtimeKitController.php` | Issues participant tokens (JSON, POST + CSRF) |
| `app/Models/Meeting.php` | Model, code generator, host-secret helpers |
| `resources/js/meeting.js` | Token fetch, SDK init, leave, disable, error states |
| `resources/views/meetings/show.blade.php` | Lobby, live meeting and "left" screens |
| `config/services.php` → `realtimekit` | Configuration (read with `config()`, never `env()`) |
| `tests/Feature/MeetingFlowTest.php` | Feature tests with a faked Cloudflare API |

### RealtimeKit SDK

- `@cloudflare/realtimekit@2.1.0` and `@cloudflare/realtimekit-ui@2.1.0`, pinned exactly. Cloudflare's release notes (2026-11-16) say the two 2.1.0 releases are meant to be used together.
- Both are bundled by the existing Vite setup: `import RealtimeKitClient from '@cloudflare/realtimekit'` and `defineCustomElements()` from `@cloudflare/realtimekit-ui/loader`. All 82 UI Kit components are bundled into `meeting-*.js`, so no CDN is needed at runtime.
- `<rtk-meeting show-setup-screen="true">`: the UI Kit's setup screen previews camera and microphone, then performs the join itself. The official Web Components quickstart does not call `meeting.join()`, so this app doesn't either.
- Leaving uses `meeting.leave()`. The page listens to `meeting.self.on('roomLeft', { state })` to show "left", "ended", "removed" or "connection lost".

## 5. Security notes

- The Cloudflare API token stays on the server. It never appears in Blade, JS, `VITE_*` variables, URLs or logs.
- Participant tokens are fetched by POST with CSRF, held in memory only, and sent with `Cache-Control: no-store`. They never go in URLs, HTML or localStorage, and are never logged.
- The Cloudflare meeting ID is always read from the database and never shown to or accepted from the browser.
- Meeting creation is rate-limited to 10/min per IP and token issuance to 20/min per IP. These are anti-abuse limits, not participant limits.
- Logs contain only the HTTP status and a sanitized error message. Bearer tokens and JWTs are redacted.
- Use HTTPS in production.

## 6. Differences from the original specification

These were verified against Cloudflare's current documentation (November 2026):

1. **There is no "delete meeting" API endpoint.** If Cloudflare creates a meeting but the local database insert fails, the app sets that meeting to `INACTIVE` and logs its Cloudflare meeting ID (an ID, not a secret) for manual cleanup.
2. **INACTIVE behaviour:** the API reference only says *"Users will not be able to join an INACTIVE meeting."* It does not say whether people already in the call are removed. As the spec requires, the app sends no separate kick-all request. When the host disables the meeting, the host's own browser leaves the call. Check in your own test whether other participants are removed immediately; either way, nobody can get a new token or rejoin.
3. **Database:** the project came with SQLite and `SESSION_DRIVER=database`. Both were switched to MySQL and `SESSION_DRIVER=file`, as the spec asks. Laravel's default framework migrations (users, cache, jobs) were left untouched.

## 7. Manual test checklist (after adding credentials)

1. Browser 1 (e.g. Chrome): Create meeting → copy link → Join. You should see the setup screen, then the meeting.
2. Browser 2 (e.g. Edge, or a Chrome incognito window): open the link → enter a name → Join. Both people should see and hear each other.
3. Browsers 3–5 (Firefox, more incognito windows, or a phone through an HTTPS tunnel): join the same link while the others are connected.
4. Check the microphone and camera toggles and the Leave button for each person. Leaving must not disable the meeting.
5. Everyone leaves, then joins again with the same link. A new session should start.
6. As a participant, there should be no Disable button, and `POST …/disable` returns 403.
7. As the host, click Disable meeting. The status changes to Disabled, and new joins are refused.

Run the automated tests with `php artisan test`.
