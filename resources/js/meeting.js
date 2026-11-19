/**
 * Live meeting page — Cloudflare RealtimeKit Web Components UI Kit.
 *
 * Flow (per the official Web Components quickstart):
 *   1. Blade renders <rtk-meeting show-setup-screen="true">.
 *   2. We POST to Laravel (with CSRF) to obtain an individual participant token.
 *   3. RealtimeKitClient.init({ authToken }) creates the meeting object.
 *   4. We assign it to the element's `meeting` property. The UI Kit's setup
 *      screen lets the user preview camera/mic and performs the join itself
 *      (the Web Components quickstart does not call meeting.join()).
 *
 * The participant token is kept in memory only. It is never logged, put in a
 * URL, persisted, or rendered into HTML.
 */
import RealtimeKitClient from '@cloudflare/realtimekit';
import { defineCustomElements } from '@cloudflare/realtimekit-ui/loader';

defineCustomElements();

const root = document.getElementById('meeting-app');

if (root) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const tokenUrl = root.dataset.tokenUrl;
    const disableUrl = root.dataset.disableUrl;
    const isHost = root.dataset.isHost === 'true';

    const els = {
        lobby: document.getElementById('lobby'),
        live: document.getElementById('live'),
        ended: document.getElementById('ended'),
        endedTitle: document.getElementById('ended-title'),
        endedMessage: document.getElementById('ended-message'),
        rejoin: document.getElementById('rejoin-button'),
        form: document.getElementById('join-form'),
        name: document.getElementById('name'),
        joinButton: document.getElementById('join-button'),
        joinError: document.getElementById('join-error'),
        loading: document.getElementById('live-loading'),
        leave: document.getElementById('leave-button'),
        networkBanner: document.getElementById('network-banner'),
        mediaBanner: document.getElementById('media-banner'),
    };

    /** Current RealtimeKit meeting object (in memory only). */
    let meeting = null;
    /** Guards against duplicate initialization / duplicate participants. */
    let joining = false;
    let disabled = root.dataset.active !== 'true';

    // ---------------------------------------------------------------- helpers

    const show = (section) => {
        for (const key of ['lobby', 'live', 'ended']) {
            els[key]?.classList.toggle('hidden', key !== section);
        }
        window.scrollTo(0, 0);
    };

    const setJoinError = (message) => {
        if (!els.joinError) return;
        els.joinError.textContent = message ?? '';
        els.joinError.classList.toggle('hidden', !message);
    };

    const setJoinBusy = (busy, label = 'Join meeting') => {
        if (!els.joinButton) return;
        els.joinButton.disabled = busy;
        els.joinButton.querySelector('[data-label]').textContent = busy ? 'Connecting…' : label;
    };

    const showEnded = (title, message, { canRejoin = true } = {}) => {
        els.endedTitle.textContent = title;
        els.endedMessage.textContent = message;
        els.rejoin.classList.toggle('hidden', !canRejoin || disabled);
        show('ended');
    };

    const showMediaBanner = (message) => {
        if (!els.mediaBanner) return;
        els.mediaBanner.textContent = message;
        els.mediaBanner.classList.remove('hidden');
        clearTimeout(showMediaBanner.timer);
        showMediaBanner.timer = setTimeout(() => els.mediaBanner.classList.add('hidden'), 10000);
    };

    /** Check browser capabilities required for WebRTC media. */
    const browserSupportError = () => {
        if (!window.isSecureContext) {
            return 'Camera and microphone require a secure connection. Open this page via https:// or http://localhost.';
        }
        if (!navigator.mediaDevices?.getUserMedia || typeof window.RTCPeerConnection === 'undefined') {
            return 'This browser does not support video calls. Please use a recent version of Chrome, Edge, Firefox or Safari.';
        }
        return null;
    };

    /** Replace <rtk-meeting> with a fresh element so a rejoin starts clean. */
    const freshMeetingElement = () => {
        const old = document.getElementById('rtk-meeting');
        const el = document.createElement('rtk-meeting');
        el.id = 'rtk-meeting';
        el.setAttribute('show-setup-screen', 'true');
        el.className = old.className;
        old.replaceWith(el);
        return el;
    };

    async function requestToken(name) {
        let response;
        try {
            response = await fetch(tokenUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ name }),
            });
        } catch {
            throw new Error('Network error: could not reach the server. Check your connection and try again.');
        }

        let body = {};
        try {
            body = await response.json();
        } catch {
            // non-JSON error page
        }

        if (!response.ok) {
            if (response.status === 410) {
                disabled = true;
            }
            if (response.status === 419) {
                throw new Error('Your session expired. Please refresh the page and try again.');
            }
            if (response.status === 429 && !body.reason) {
                throw new Error('Too many join attempts. Please wait a minute and try again.');
            }
            const validation = body.errors ? Object.values(body.errors).flat()[0] : null;
            throw new Error(validation || body.message || 'Could not get a meeting token. Please try again.');
        }

        if (typeof body.token !== 'string' || body.token === '') {
            throw new Error('The server returned an invalid meeting token.');
        }
        return body.token;
    }

    function attachMeetingListeners(m) {
        m.self.on('roomJoined', () => {
            els.loading?.classList.add('hidden');
        });

        m.self.on('roomLeft', ({ state }) => {
            meeting = null;
            joining = false;
            switch (state) {
                case 'left':
                    showEnded('You left the meeting', 'The meeting is still open for others. You can rejoin with the same link.');
                    break;
                case 'ended':
                    showEnded('The meeting session ended', 'You can start a new session with the same link while the meeting is active.');
                    break;
                case 'kicked':
                    showEnded('You were removed from the meeting', disabled
                        ? 'The host has disabled this meeting.'
                        : 'You were removed from this session.');
                    break;
                case 'rejected':
                    showEnded('Unable to join', 'Your request to join this meeting was rejected.');
                    break;
                case 'disconnected':
                case 'failed':
                    showEnded('Connection lost', 'Your connection to the meeting was lost. Check your network and rejoin.');
                    break;
                default:
                    // e.g. 'stageLeft' / 'connected-meeting' are not full exits
                    meeting = m;
            }
        });

        const permissionMessages = {
            DENIED: 'was blocked. Allow access in your browser\'s site settings, then reload.',
            SYSTEM_DENIED: 'is blocked by your operating system privacy settings.',
            CANCELED: 'permission request was dismissed.',
            COULD_NOT_START: 'could not be started. It may be in use by another application.',
            NO_DEVICES_AVAILABLE: 'was not found on this device.',
        };
        m.self.on('mediaPermissionError', ({ kind, message }) => {
            const device = kind === 'audio' ? 'Microphone' : kind === 'video' ? 'Camera' : 'Screen share';
            showMediaBanner(`${device} ${permissionMessages[message] ?? 'is unavailable.'}`);
        });
    }

    async function joinMeeting() {
        if (joining || meeting) return; // avoid duplicate initialization
        if (disabled) {
            setJoinError('This meeting has been disabled by the host.');
            return;
        }

        const name = els.name.value.trim();
        if (!name) {
            setJoinError('Please enter your name.');
            els.name.focus();
            return;
        }
        if (name.length > 100) {
            setJoinError('Your name must be 100 characters or fewer.');
            return;
        }

        const unsupported = browserSupportError();
        if (unsupported) {
            setJoinError(unsupported);
            return;
        }

        joining = true;
        setJoinError(null);
        setJoinBusy(true);

        let authToken;
        try {
            authToken = await requestToken(name);
        } catch (error) {
            joining = false;
            setJoinBusy(false);
            setJoinError(error.message);
            show('lobby');
            return;
        }

        show('live');
        els.loading?.classList.remove('hidden');

        try {
            await customElements.whenDefined('rtk-meeting');
            const el = freshMeetingElement();

            const m = await RealtimeKitClient.init({ authToken });
            authToken = null; // drop our reference; the SDK holds what it needs

            attachMeetingListeners(m);
            meeting = m;
            el.meeting = m;
            // The setup screen is now visible; hide our spinner.
            els.loading?.classList.add('hidden');
        } catch (error) {
            meeting = null;
            joining = false;
            setJoinBusy(false);
            setJoinError('Could not start the video meeting. ' + (error?.message ? `(${error.message})` : 'Please try again.'));
            show('lobby');
            return;
        }

        setJoinBusy(false);
        joining = false;
    }

    async function leaveMeeting() {
        const m = meeting;
        if (!m) {
            showEnded('You left the meeting', 'The meeting is still open for others. You can rejoin with the same link.');
            return;
        }
        els.leave.disabled = true;
        try {
            await m.leave();
            // roomLeft handler switches the view; fall back if it did not fire.
            if (meeting === m) {
                meeting = null;
                showEnded('You left the meeting', 'The meeting is still open for others. You can rejoin with the same link.');
            }
        } catch {
            meeting = null;
            showEnded('You left the meeting', 'The meeting is still open for others.');
        } finally {
            els.leave.disabled = false;
        }
    }

    async function disableMeeting(button) {
        if (!isHost || disabled) return;
        if (!window.confirm('Disable this meeting for everyone? No one will be able to join it again.')) return;

        const buttons = document.querySelectorAll('[data-disable-button]');
        buttons.forEach((b) => (b.disabled = true));

        try {
            const response = await fetch(disableUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(body.message || 'The meeting could not be disabled.');
            }

            disabled = true;
            if (meeting) {
                const m = meeting;
                meeting = null;
                try {
                    await m.leave();
                } catch {
                    // ignore — we are leaving anyway
                }
            }
            showEnded('Meeting disabled', body.message || 'No one can join this meeting anymore.', { canRejoin: false });
        } catch (error) {
            window.alert(error.message || 'The meeting could not be disabled.');
            buttons.forEach((b) => (b.disabled = false));
        }
    }

    // ------------------------------------------------------------ wiring

    els.form?.addEventListener('submit', (event) => {
        event.preventDefault();
        joinMeeting();
    });

    els.leave?.addEventListener('click', leaveMeeting);

    els.rejoin?.addEventListener('click', () => {
        // A fresh participant token is requested for every join.
        show('lobby');
        joinMeeting();
    });

    document.querySelectorAll('[data-disable-button]').forEach((button) => {
        button.addEventListener('click', () => disableMeeting(button));
    });

    window.addEventListener('offline', () => els.networkBanner?.classList.remove('hidden'));
    window.addEventListener('online', () => els.networkBanner?.classList.add('hidden'));

    // Best-effort cleanup when the tab is closed or navigated away.
    window.addEventListener('pagehide', () => {
        if (meeting) {
            meeting.leave().catch(() => {});
        }
    });
}
