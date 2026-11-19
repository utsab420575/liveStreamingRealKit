# AI Development Specification: Simple Laravel 12 + Cloudflare RealtimeKit Meeting App

> **Purpose:** Build a small, working Zoom-like video meeting prototype
> inside the user's existing Laravel 12 project. This is a testing MVP,
> not a production-grade conferencing platform.
>
> **Important:** Implement the application only. Do not create a new
> Laravel project. Do not ask the user to paste credentials into chat.
> The user will add Cloudflare credentials to `.env` locally.

------------------------------------------------------------------------

## 1. Project context and fixed decisions

The user already has an empty Laravel 12 project created in **Laragon on
Windows**.

### Technology

-   Backend: Laravel 12 / PHP
-   Frontend: Blade, HTML, CSS, Tailwind CSS, JavaScript
-   Build tool: existing Laravel Vite setup
-   Database: MySQL
-   Video: Cloudflare RealtimeKit
-   RealtimeKit frontend: **RealtimeKit Web Components UI Kit** (not
    React, not Vue)
-   Local development: Laragon
-   No user registration or login
-   No admin dashboard

### Confirmed product decisions

1.  One MySQL table named `meetings` only.
2.  Anyone who has a valid meeting link/code may join. No host approval
    or waiting room in this MVP.
3.  Reuse the same RealtimeKit Meeting and application meeting link
    until the host disables it.
4.  A RealtimeKit Session is created/managed automatically by
    RealtimeKit when participants join; do not create Sessions manually.
5.  Do not implement webhooks in this first version.
6.  The user will supply Cloudflare Account ID, App ID and API Token in
    `.env` after the code is generated.
7.  The app must support multiple participants in one meeting
    simultaneously. Two browsers are only the minimum smoke test, not a
    two-person product limit. Test with at least 3--5 participants where
    practical.
8.  Do not hardcode a two-participant limit or any arbitrary small
    participant cap in Laravel, JavaScript, Blade, or the database. The
    effective maximum is governed by the RealtimeKit app/meeting
    configuration, preset, account limits and current Cloudflare product
    limits.

### Scope boundary

Build only:

-   Home page
-   Create meeting
-   Shareable meeting URL and code
-   Join by link or code
-   Name entry for host/participants
-   Multi-participant live meeting page using RealtimeKit UI Kit (host
    plus multiple participants in the same meeting)
-   Individual RealtimeKit participant token for every person who joins
-   Leave meeting
-   Host can disable/end access to the meeting
-   Basic validation and friendly error messages

Do **not** build:

-   Registration, login, password reset or user profiles
-   Payment/subscription
-   Scheduling/calendar
-   Recording, transcription, AI summaries
-   Chat, polls, breakout rooms
-   Custom video grid or custom WebRTC implementation
-   Admin dashboard
-   Participant attendance/history table
-   Webhook endpoint
-   Production deployment or domain configuration
-   Complex role/permission management

Do not add extra features unless required to make the MVP function.

------------------------------------------------------------------------

## 2. Understand the Cloudflare concepts

### Meeting

A RealtimeKit Meeting is a reusable virtual room. It has a Cloudflare
meeting ID and can exist while nobody is connected.

### Session

A Session is one live occurrence of a Meeting. It starts automatically
when the first participant joins and ends shortly after the last
participant leaves. The same Meeting can have multiple Sessions over
time.

### Participant and token

A person cannot join through the browser SDK merely by knowing the
meeting ID. The Laravel backend must call the RealtimeKit **Add
Participant** API separately for every person who joins. Cloudflare
returns a participant ID and an authentication token for each person.
The browser passes that person's token to the RealtimeKit JavaScript
SDK.

A single RealtimeKit Meeting can contain the host and multiple
participants concurrently. Do not design the application around only two
users or create a separate RealtimeKit Meeting for each participant.
Every person joins the same Cloudflare Meeting ID, but receives an
individual participant identity/token and the appropriate server-side
preset. RealtimeKit manages the multiparty media connections and the UI
Kit renders the participant experience.

A valid participant token can be used for multiple Sessions of the same
Meeting until it expires or is revoked. For this MVP, issue a fresh
participant token whenever a person requests to join. Never store or
expose the Cloudflare API token in frontend code.

### Presets

RealtimeKit applies a preset to each participant. Presets define
permissions and role behaviour. The implementation must not guess preset
names. Read the configured preset names from `.env` and explain in the
README that they must match presets available in the RealtimeKit app
dashboard.

Use separate host and participant preset settings if available. If the
user's RealtimeKit app only has one suitable default preset, the same
preset may be used for both in this prototype. The app should not
pretend that host controls exist if the selected preset does not grant
them.

------------------------------------------------------------------------

## 3. Inspect the existing project before changing anything

Before coding, note that the user has an empty Laravel 12 project and
**does not have a ZIP or pre-created application source files**. Create
all required application files yourself according to this specification;
do not instruct the user to unzip/copy source files.

Before coding:

1.  Inspect `composer.json`, `package.json`, `.env.example`,
    `vite.config.js`, `resources/js/app.js`, `resources/css/app.css`,
    `routes/web.php`, and existing Laravel files.
2.  Confirm the project is Laravel 12.
3.  Preserve existing working configuration and dependencies.
4.  Do not overwrite the project, recreate it, or replace its Laravel
    version.
5.  Do not delete unrelated files.
6.  If Tailwind is already configured, use the installed version and
    existing configuration. Do not install a second Tailwind setup.
7.  If Vite is already configured, extend it rather than replacing it.
8.  Use the existing Laravel application key and database configuration.
    Do not regenerate the app key.

If any assumption differs from the specification, adapt carefully and
document the difference.

------------------------------------------------------------------------

## 4. Database: one table only

Create one migration for `meetings`.

Suggested columns:

  ----------------------------------------------------------------------
  Column                   Type                   Notes
  ------------------------ ---------------------- ----------------------
  id                       BIGINT primary key     Laravel default

  title                    VARCHAR(150)           Meeting title

  meeting_code             VARCHAR(20), unique    Random,
                                                  human-shareable code

  realtimekit_meeting_id   VARCHAR(100), unique   Cloudflare Meeting ID

  host_name                VARCHAR(100)           Display name supplied
                                                  by creator

  host_secret_hash         VARCHAR(255)           Hash of host-only
                                                  management secret

  status                   VARCHAR(20)            `active` or `disabled`

  created_at               timestamp              Laravel

  updated_at               timestamp              Laravel
  ----------------------------------------------------------------------

Use sensible indexes and constraints. Do not add a `users` table or
`meeting_participants` table for this MVP. Laravel's default migrations
may already include framework tables; do not add or use user
authentication. The only application-specific table must be `meetings`.
If practical, use Laravel's `file` session driver for this local
prototype so host session data does not require an additional database
sessions table. Do not remove framework migrations that the existing
project needs.

### Model

Create `App\Models\Meeting` with:

-   fillable fields for title, meeting_code, realtimekit_meeting_id,
    host_name and status; handle `host_secret_hash` explicitly and never
    mass-assign it from user input
-   appropriate casts if needed
-   simple helper methods/scopes only if useful

### Meeting code

Generate a random, URL-safe, unique meeting code (for example 6--10
uppercase letters/numbers). Check uniqueness before saving. Do not use
sequential database IDs as public meeting codes.

### Meeting lifecycle in our database

-   `active`: the meeting link can be used to request a participant
    token.
-   `disabled`: no new participant tokens may be issued.

Do not automatically mark a meeting disabled merely because all
participants leave. The RealtimeKit Meeting is reusable and can host a
later Session.

------------------------------------------------------------------------

## 5. Environment configuration

Add placeholders to `.env.example` and document the same keys in README.
The user will enter real values later.

Recommended variables:

``` dotenv
CLOUDFLARE_ACCOUNT_ID=
REALTIMEKIT_APP_ID=
CLOUDFLARE_API_TOKEN=

# Must exactly match preset names configured in the RealtimeKit dashboard.
REALTIMEKIT_HOST_PRESET_NAME=
REALTIMEKIT_PARTICIPANT_PRESET_NAME=
```

Use `config/services.php` to expose these values to Laravel services.
Never call `env()` directly from controllers/services after
configuration caching; use `config()`.

Never commit the real `.env` file. Do not put API secrets in Blade,
JavaScript, HTML attributes, Vite `VITE_` variables, URLs, browser local
storage, or console logs.

If credentials are missing, show a helpful server-side configuration
error rather than attempting a request.

------------------------------------------------------------------------

## 6. RealtimeKit API integration

Create a small service class:

`app/Services/RealtimeKitService.php`

Use Laravel's `Illuminate\Support\Facades\Http` client. Keep all
Cloudflare API calls in this service, not scattered across controllers.

Base URL:

``` text
https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/realtime/kit/{APP_ID}
```

Headers:

``` text
Authorization: Bearer {CLOUDFLARE_API_TOKEN}
Content-Type: application/json
```

Use a sensible timeout and handle connection errors, non-2xx responses,
and Cloudflare responses where `success` is false. Do not expose raw
secrets or full token responses in logs or user-facing errors.

### A. Create RealtimeKit Meeting

Request:

``` http
POST /meetings
```

JSON body:

``` json
{
  "title": "Meeting title"
}
```

Read the returned meeting ID from the successful response (`data.id`
according to the current API response). Validate that it exists before
saving the local Meeting record.

### B. Add participant and obtain token

Request:

``` http
POST /meetings/{realtimekit_meeting_id}/participants
```

JSON body must include:

``` json
{
  "name": "Participant display name",
  "preset_name": "configured preset name",
  "custom_participant_id": "unique-random-uuid",
  "token": {
    "expires_on": "RFC3339 timestamp approximately one hour in the future"
  }
}
```

Use a UUID for `custom_participant_id`. Do not use email, phone number
or other personal data. Select host or participant preset according to
the local role.

Read participant `id` and `token` from the successful response
(`data.id` and `data.token`). Set `token.expires_on` to a short validity
window (one hour after issuance is suitable for this testing MVP), using
an RFC 3339 timestamp with timezone. Cloudflare defaults to 100 days if
no custom expiry is supplied. Return only the participant token and any
minimum data needed by the frontend. Do not persist participant rows
because the MVP intentionally has one application-specific table only.
Issue a separate participant token for every person joining; never reuse
one person's token for another participant.

### C. Disable meeting

Use the documented Update Meeting endpoint:

``` http
PATCH /meetings/{realtimekit_meeting_id}
```

JSON body:

``` json
{
  "status": "INACTIVE"
}
```

Cloudflare documents that setting a meeting to `INACTIVE` removes all
participants from its active session, if one exists, and prevents new
participants from joining or starting new Sessions. Do not make a
separate `kick-all` API request after this operation.

When the host disables a meeting: - Verify the host's session/secret. -
Call the Cloudflare Update Meeting operation. - Only mark the local
record `disabled` after Cloudflare confirms success. - If the Cloudflare
call fails, show an error and do not falsely report success. - Reject
all subsequent token requests for this local meeting.

Use the Cloudflare API documentation linked below to verify the current
request path, response structure and accepted status values before
implementation.

### API reliability

-   Validate every API response before using it.
-   Use Laravel HTTP client timeouts.
-   Do not blindly retry meeting creation, because retries could create
    duplicate Cloudflare meetings.
-   Use clear exception classes or controlled error results.
-   Log safe error context only (HTTP status and sanitized error
    message); never log API tokens or participant JWTs.

------------------------------------------------------------------------

## 7. Backend classes and responsibilities

Create only the necessary classes.

### Controllers

`app/Http/Controllers/MeetingController.php`

Responsibilities:

-   show home page
-   show create meeting page
-   create meeting
-   show join-by-code page
-   resolve and display meeting details
-   validate meeting is active
-   show live meeting entry page
-   disable meeting (host-only protection appropriate to this no-login
    MVP)

`app/Http/Controllers/RealtimeKitController.php` (or a clearly named
token controller)

Responsibilities:

-   validate participant display name
-   validate meeting exists and is active
-   determine whether the request is from host or participant
-   call RealtimeKitService to add participant
-   return participant token to the browser through a protected JSON
    response

`app/Services/RealtimeKitService.php`

Responsibilities:

-   create Cloudflare Meeting
-   add Cloudflare participant and obtain token
-   disable Cloudflare Meeting using verified documented API
-   centralize API configuration, requests and error handling

Avoid unnecessary repositories, interfaces, events, jobs or service
layers.

### Host identification without login

There is no account system. The app must still prevent any random
visitor from using the public meeting URL to disable someone else's
meeting.

Use a simple host secret:

-   When creating a meeting, generate a cryptographically secure random
    host-management secret.
-   Store only a hash of that secret in the `meetings` table (add a
    `host_secret_hash` column to the single meetings table).
-   Keep the raw host secret in the creator's session (or a secure
    HttpOnly session cookie) and never show it in the public URL.
-   Only allow the browser session that created the meeting to invoke
    the disable action.
-   Participants who only know the public meeting link/code must not be
    able to disable the meeting.
-   Do not add a second table.

The host secret is an internal authorization mechanism for this
prototype, not a replacement for real user authentication.

If the host session is lost, there is no account recovery in this MVP.

------------------------------------------------------------------------

## 8. Routes

Use named Laravel routes and appropriate HTTP verbs. Suggested routes:

  ----------------------------------------------------------------------------------------
  Method   URI                                          Name                 Purpose
  -------- -------------------------------------------- -------------------- -------------
  GET      `/`                                          `home`               Home

  GET      `/meetings/create`                           `meetings.create`    Create form

  POST     `/meetings`                                  `meetings.store`     Create
                                                                             meeting

  GET      `/meetings/join`                             `meetings.join`      Join by code
                                                                             form

  POST     `/meetings/resolve`                          `meetings.resolve`   Resolve
                                                                             meeting code

  GET      `/meetings/{meeting:meeting_code}`           `meetings.show`      Meeting
                                                                             information /
                                                                             join name
                                                                             form

  POST     `/meetings/{meeting:meeting_code}/token`     `meetings.token`     Request
                                                                             participant
                                                                             token

  POST     `/meetings/{meeting:meeting_code}/disable`   `meetings.disable`   Host disables
                                                                             meeting
  ----------------------------------------------------------------------------------------

Use POST for token generation and disable operations. Apply Laravel CSRF
protection to browser form/AJAX requests. Validate all route parameters
and input. Use route model binding by `meeting_code` if implemented
correctly.

The live meeting page may be rendered by `meetings.show` after a
participant name is submitted, or by a separate GET route. Keep the
route design straightforward.

------------------------------------------------------------------------

## 9. User experience and Blade pages

Create a clean, responsive, simple interface using the existing Tailwind
setup. Avoid overdesign.

Suggested Blade files:

``` text
resources/views/
  layouts/app.blade.php
  home.blade.php
  meetings/create.blade.php
  meetings/join.blade.php
  meetings/show.blade.php
```

### Home page

Show: - App title, e.g. "Simple Live Meeting" - Short description -
"Create Meeting" button - "Join Meeting" button

### Create meeting page

Fields: - Meeting title - Host display name

On submit: 1. Validate fields. 2. Create RealtimeKit Meeting through
backend service. 3. Create local database record. 4. Generate and store
host secret securely in session. 5. Display meeting link and meeting
code. 6. Provide a copy-link button using browser clipboard API with a
fallback.

### Join meeting page

Fields: - Meeting code - Participant display name

Also allow a participant to open a shared URL directly.

### Meeting information / entry page

Display: - Meeting title - Meeting code - Meeting status - Name input if
not already provided - Join button

Do not reveal Cloudflare meeting IDs or API credentials.

### Live meeting page

-   Embed the RealtimeKit Web Components UI Kit.
-   Support a host plus multiple participants concurrently in the same
    meeting. Do not impose a two-person restriction.
-   Use the UI Kit's supported participant grid/list/layout. Let the UI
    Kit manage responsive layout, pagination or scrolling as supported
    by the installed version; do not build a custom video grid.
-   Make sure every joining person receives an individual participant
    token and joins the same RealtimeKit Meeting ID.
-   Show a loading state while the SDK initializes.
-   Show a clear error if token retrieval or SDK initialization fails.
-   Provide a visible leave/end experience.
-   If the current user is host, show "Disable meeting" separately from
    "Leave meeting".
-   A normal participant leaving must not disable the reusable Meeting.
-   Do not claim the session ended just because one participant left.

------------------------------------------------------------------------

## 10. RealtimeKit Web Components UI Kit integration

This app uses Blade, not React. Do not install React or convert the
Laravel app to a SPA.

Follow the current Cloudflare Quickstart's **Web Components** path. This
project uses Blade and plain JavaScript, so do not use React or React UI
Kit packages.

Cloudflare's current Web Components quickstart documents loading the UI
Kit custom-element loader and RealtimeKit Core browser SDK. Use the
official integration pattern and pin mutually compatible package
versions (rather than relying blindly on `@latest`) after checking the
current Cloudflare release notes. The documented CDN pattern is:

``` html
<script type="module">
  import { defineCustomElements } from
    "https://cdn.jsdelivr.net/npm/@cloudflare/realtimekit-ui@<compatible-version>/loader/index.es2017.js";
  defineCustomElements();
</script>
<script src="https://cdn.jsdelivr.net/npm/@cloudflare/realtimekit@<compatible-version>/dist/browser.js"></script>
```

Replace `<compatible-version>` with the exact compatible version
verified from Cloudflare's current release notes. If you choose npm/Vite
instead, use the package's documented Web Components entry point and
prove it works with the existing Laravel Vite build. Do not invent a
custom `import.meta.glob` bundling workaround unless the official
supported approach has been tested and shown not to work. Do not mix
React examples with Web Components code.

The UI Kit uses the custom element:

``` html
<rtk-meeting id="my-meeting" show-setup-screen="true"></rtk-meeting>
```

The JavaScript flow must be:

1.  Blade page renders the custom element.
2.  Browser requests a participant token from Laravel using a POST
    request and CSRF token.
3.  Laravel validates the local meeting and calls Add Participant API.
4.  Laravel returns the participant auth token in the response.
5.  Browser initializes the RealtimeKit client with that token.
6.  Assign the initialized meeting object to the custom element's
    `meeting` property as documented.
7.  UI Kit handles the meeting interface, media controls and participant
    UI.

The official Web Components quickstart demonstrates this initialization
pattern (the SDK exposes `RealtimeKitClient` in its browser build):

``` javascript
const meeting = await RealtimeKitClient.init({
    authToken: participantToken
});

document.getElementById('my-meeting').meeting = meeting;
```

Use the exact global/import/loader API that matches the installed
package version. If using the browser CDN method from the official
quickstart, load the UI Kit loader and core browser SDK exactly as
documented. Prefer npm/Vite for this Laravel app if it works reliably.

Do not put participant tokens in query strings, permanent links,
localStorage or server-rendered HTML. Retrieve the token on demand and
keep it in memory only. Do not log it.

Use `show-setup-screen="true"` so users can check camera/microphone
before entering. Follow the exact Web Components quickstart for the
selected SDK version regarding whether the UI Kit handles joining or an
explicit `meeting.join()` call is needed. Do not add or omit a join call
by guesswork.

### JavaScript file

Create a focused file such as:

`resources/js/meeting.js`

It should: - wait until the UI custom element is defined/ready - read
only non-secret meeting route data from HTML data attributes or a safe
JSON script block - POST to the Laravel token endpoint - include the
CSRF token - handle validation/API/network errors - initialize the SDK -
attach the meeting object to `<rtk-meeting>` - handle leave and cleanup
using documented SDK methods - avoid duplicate initialization - never
expose Cloudflare API credentials

Use `resources/js/app.js` and Vite in the existing Laravel structure. Do
not add a separate frontend framework.

------------------------------------------------------------------------

## 11. Host and participant behaviour

### Host

1.  Opens homepage.
2.  Clicks Create Meeting.
3.  Enters meeting title and host display name.
4.  Laravel creates the RealtimeKit Meeting.
5.  Laravel stores its Cloudflare ID and a unique public meeting code.
6.  Host receives the shareable URL.
7.  Host requests a participant token with the host preset.
8.  Host joins the RealtimeKit UI.

### Participant

1.  Opens shared link or enters meeting code on homepage.
2.  Enters display name.
3.  Laravel checks that the meeting exists and is `active`.
4.  Laravel calls Add Participant with participant preset, creating a
    distinct participant identity and token for this person.
5.  Laravel returns this participant's token.
6.  Browser initializes RealtimeKit SDK and joins the same Meeting.

### Multiple participants

-   The host and any number of additional participants permitted by the
    RealtimeKit app/meeting configuration may join the same Meeting
    concurrently.
-   The application must not contain a hardcoded limit of two
    participants.
-   Each person must receive their own participant token and unique
    `custom_participant_id`.
-   All participants join the same `realtimekit_meeting_id`; do not
    create separate meetings for each person.
-   Use the RealtimeKit UI Kit's built-in participant layout. Do not
    assume every video tile must fit on one screen; the UI Kit may use
    responsive layout, scrolling or pagination depending on the version.
-   Do not promise unlimited capacity. The actual supported capacity is
    determined by current Cloudflare RealtimeKit limits and the app's
    meeting/preset configuration. Do not invent or hardcode a maximum.
-   If Cloudflare rejects additional participants because a configured
    limit has been reached, show a clear, friendly message and do not
    create a local participant record (there is no participant table).

### Reuse behaviour

-   The public link and local meeting record remain the same across
    Sessions.
-   When all users leave, RealtimeKit ends the current Session
    automatically.
-   Later, users can use the same link to join a new Session, provided
    the local meeting is still active and Cloudflare still permits it.
-   Do not create a new Cloudflare Meeting each time someone joins.
-   Create a Cloudflare Meeting only when the host creates a new local
    meeting.

### Host disable behaviour

-   Host clicks "Disable meeting".
-   Laravel verifies the host secret/session.
-   Laravel disables the Cloudflare Meeting using the verified API.
-   Laravel updates local status to `disabled`.
-   All later token requests are rejected.
-   Use the documented Cloudflare INACTIVE operation. According to the
    current Cloudflare documentation, this operation removes
    participants from the active Session and prevents new Sessions. Do
    not add a separate kick-all request unless Cloudflare's current
    documentation changes this behavior.

------------------------------------------------------------------------

## 12. Validation and security

Minimum requirements:

-   Validate meeting title and names with reasonable length limits.
-   Validate meeting code format.
-   Use cryptographically secure random meeting codes and host secrets.
-   Enforce unique meeting code.
-   Never trust a meeting ID supplied by the browser; load it from the
    local database record.
-   Never accept arbitrary preset names from the browser; use
    server-side configuration.
-   Keep Cloudflare API token server-side only.
-   Use CSRF protection.
-   Escape user-provided names and titles in Blade (normal Blade `{{ }}`
    escaping).
-   Rate-limit token issuance and meeting creation to reduce abuse.
-   Avoid creating multiple Cloudflare participants due to accidental
    repeated clicks; disable the join button while a request is in
    progress.
-   Do not log participant tokens or API secrets.
-   Validate that a meeting is active before every token request.
-   Use host session authorization for disable.
-   Do not expose host secret in public URLs.
-   Do not return raw exception traces to users.
-   Use HTTPS in production. Browser camera/microphone access generally
    requires a secure context; `localhost` is allowed for local
    development.
-   Explain that a public meeting link is a bearer invitation: anyone
    who obtains it can join in this MVP.

------------------------------------------------------------------------

## 13. Error handling

Display user-friendly errors for:

-   Cloudflare credentials missing
-   Cloudflare API authentication failure
-   Meeting creation failure
-   Invalid or unknown meeting code
-   Meeting disabled
-   Preset missing/incorrect
-   Participant token request failure
-   Camera/microphone permission denied
-   Browser/device does not support required media features
-   Network disconnection
-   RealtimeKit SDK initialization failure

Log sanitized server-side details. Never show API token, participant
JWT, stack trace or secrets.

If meeting creation succeeds in Cloudflare but the local database write
fails, handle the partial failure carefully. If the API supports
deleting the newly created meeting, clean it up using the documented
API; otherwise log the orphaned Cloudflare meeting ID safely for manual
cleanup. Do not silently report success.

------------------------------------------------------------------------

## 14. Testing checklist

The implementation is not complete until the following tests pass.

### Local setup

-   [ ] Existing Laravel 12 project remains intact.
-   [ ] MySQL connection works.
-   [ ] Migration runs successfully.
-   [ ] Homepage loads.
-   [ ] Tailwind/Vite assets compile.
-   [ ] Missing credentials produce a clear setup message.

### Meeting creation

-   [ ] Host can create a meeting with title and name.
-   [ ] One local `meetings` row is created.
-   [ ] A Cloudflare Meeting is created.
-   [ ] A unique meeting code is generated.
-   [ ] Shareable URL opens the correct meeting.
-   [ ] Copy-link button works.

### Joining

-   [ ] Participant can join using code.
-   [ ] Participant can join using the shared URL.
-   [ ] Invalid code is rejected.
-   [ ] Disabled meeting is rejected.
-   [ ] Host and participant receive correct configured presets.
-   [ ] Participant token is not exposed in the URL or logs.

### Live video and multiple participants

-   [ ] Host can join from Browser 1.
-   [ ] At least one participant can join from Browser 2 or another
    device.
-   [ ] Host and participant can see and hear each other.
-   [ ] A third participant can join the same meeting while the first
    two are connected.
-   [ ] Test with 3--5 participants where practical, using separate
    browsers/devices.
-   [ ] All participants appear in the RealtimeKit UI Kit's supported
    participant layout.
-   [ ] No Laravel, Blade or JavaScript code restricts the meeting to
    two participants.
-   [ ] Each participant receives a distinct token and participant
    identity.
-   [ ] All connected participants can hear one another as supported by
    the RealtimeKit meeting configuration.
-   [ ] Microphone toggle works for each participant.
-   [ ] Camera toggle works for each participant.
-   [ ] Leave action works for each participant.
-   [ ] Leaving by one participant does not disable the meeting.
-   [ ] When everyone leaves, a later join through the same link can
    create a new Session.

### Disable

-   [ ] Host can disable the meeting.
-   [ ] A participant cannot disable the meeting.
-   [ ] Disabled meeting cannot issue new participant tokens.
-   [ ] UI clearly reports disabled status.

Test camera/microphone on `http://localhost` or HTTPS. For a second
physical device, use a secure HTTPS tunnel or HTTPS development host;
another device accessing a plain HTTP LAN address may not receive
camera/microphone permissions.

------------------------------------------------------------------------

## 15. Developer execution instructions

Work in small steps and keep the project runnable.

1.  Inspect existing project.
2.  Report a short implementation plan.
3.  Implement database migration and model.
4.  Implement RealtimeKit service and configuration.
5.  Implement controllers and routes.
6.  Implement Blade pages and Tailwind styling.
7.  Install/configure Web Components SDK.
8.  Implement browser-side token retrieval and SDK initialization.
9.  Implement host disable behaviour.
10. Run migrations and frontend build.
11. Run available Laravel tests and fix errors.
12. Provide a final summary of files created/changed, commands run, and
    manual steps still required.

Do not treat a two-browser test as proof of multi-participant support.
After the basic two-browser smoke test, test a third participant and,
where practical, 3--5 simultaneous participants. The supported maximum
must be taken from current Cloudflare configuration/limits, not guessed.

Do not fabricate successful Cloudflare integration tests when the user
has not yet entered credentials. In that case, verify the local
code/build and clearly state that live Cloudflare testing is pending
credentials.

Do not ask the user to provide secrets in chat. The final instructions
to the user should say to fill the `.env` values locally and run the
app.

### Expected commands

Use the existing project folder in Laragon. Typical commands (adjust
only if the project already uses a different setup):

``` bash
php artisan migrate
npm install
npm run build
php artisan serve
```

If Laragon's virtual host is already configured, use that URL instead of
`php artisan serve`.

------------------------------------------------------------------------

## 16. Official Cloudflare documentation

Use current official documentation as the source of truth. Verify
endpoint paths, response fields, preset configuration and Web Components
API against these pages before coding. Do not rely on outdated blog
posts or invent SDK methods.

-   RealtimeKit overview:
    https://developers.cloudflare.com/realtime/realtimekit/
-   Quickstart:
    https://developers.cloudflare.com/realtime/realtimekit/quickstart/
-   Meeting concept:
    https://developers.cloudflare.com/realtime/realtimekit/concepts/meeting/
-   Participant concept:
    https://developers.cloudflare.com/realtime/realtimekit/concepts/participant/
-   Webhooks (not required in this MVP):
    https://developers.cloudflare.com/realtime/realtimekit/webhooks/
-   Create Meeting API:
    https://developers.cloudflare.com/api/resources/realtime_kit/subresources/meetings/methods/create/
-   Add Participant API:
    https://developers.cloudflare.com/api/resources/realtime_kit/subresources/meetings/methods/add_participant/
-   Meetings API reference:
    https://developers.cloudflare.com/api/resources/realtime_kit/subresources/meetings/

------------------------------------------------------------------------

## 17. Definition of done

The MVP is complete when:

**A host can create one reusable meeting, share its link/code, and
multiple other people can join the same meeting concurrently from
different browsers/devices, subject to the configured RealtimeKit
capacity. Each participant has an individual token. Participants can
communicate through live video and audio using the RealtimeKit UI Kit.
Any participant can leave without destroying the reusable meeting. The
host can disable the meeting so that no new participants can join. The
application must not impose a two-person limit.**

Keep the implementation small, readable, maintainable and easy to extend
later. Do not turn this prototype into a full Zoom clone.
