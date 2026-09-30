/**
 * Singleton EventSource multiplexer.
 *
 * Instead of each Stimulus controller opening its own EventSource (browsers
 * cap these at ~6 per domain), every real-time consumer goes through this bus:
 *
 *   import mercureBus from '../../services/mercure_bus';
 *
 *   const unsubscribe = mercureBus.onMessage((payload, event) => { ... });
 *
 * The hub URL and the token endpoint come from two page meta tags:
 *   - meta[name="mercure-hub"]           Mercure public URL
 *   - meta[name="mercure-token-url"]     Symfony route that mints the JWT
 *
 * Topics are **not** declared client-side. The token endpoint
 * (`/admin/mercure-token` for super-admins, `/organization/mercure-token`
 * for org users) returns `{ token, subscribed: [...] }`; `subscribed[]` is
 * the authoritative list used both as the JWT allow-list and as the
 * EventSource subscription list. This avoids the drift that would happen
 * if Twig templates had to enumerate channels separately.
 *
 * Additional topics can still be registered at runtime via `addTopic(topic)`
 * — the notification bell uses this to watch `/notifications/{userId}`
 * when its Stimulus values are only known at controller connect.
 *
 * Resilience:
 *   - Last-Event-ID is persisted in sessionStorage between navigations so
 *     the hub replays anything emitted during a BRIEF disconnect.
 *   - Native EventSource auto-reconnect handles transient network failures.
 *
 * ⚠️ The replay is bounded in time (default 5 minutes, override with
 * `<meta name="mercure-replay-max-age" content="<seconds>">`). A hub with
 * history replays EVERYTHING that followed the ID it is given: a tab that
 * received its last event at 11:56 and reconnected at 16:53 was served five
 * hours of stale `created` events at once — and every matching table reloaded
 * for each of them. Past the bound, the bus reconnects WITHOUT an ID: a table
 * that missed that much reloads anyway, it does not need the history. The same
 * bound applies to the browser's own reconnect, which sends the last ID it saw
 * however old it is (a laptop waking up): the bus replaces it with a fresh
 * connection.
 */

const LAST_EVENT_ID_KEY = 'mercure_last_event_id';
const DEFAULT_REPLAY_MAX_AGE_SECONDS = 300;

class MercureBus {
    constructor() {
        this._eventSource = null;
        this._handlers = new Set();
        this._topics = new Set();
        this._connectPromise = null;
        this._initialized = false;
        // `{ id, at }` — the ID and when it was RECEIVED, so its age can be told.
        this._lastEvent = this._readLastEvent();
    }

    onMessage(handler) {
        this._handlers.add(handler);
        this._ensureConnected();
        return () => { this._handlers.delete(handler); };
    }

    addTopic(topic) {
        if (!topic || this._topics.has(topic)) return;
        this._topics.add(topic);
        if (this._initialized) {
            this._reconnect();
        }
    }

    _ensureConnected() {
        if (this._initialized) return;
        this._initialized = true;
        this._connect();
    }

    async _connect() {
        const hubUrl = document.querySelector('meta[name="mercure-hub"]')?.content;
        const tokenUrl = document.querySelector('meta[name="mercure-token-url"]')?.content;
        if (!hubUrl || !tokenUrl) return;

        let data;
        try {
            data = await fetch(tokenUrl, { credentials: 'same-origin' }).then(r => r.json());
        } catch {
            return;
        }
        if (!data || !data.token) return;

        // The server is the single source of truth — add whatever the
        // token endpoint authorised for this user.
        if (Array.isArray(data.subscribed)) {
            data.subscribed.forEach(topic => {
                if (typeof topic === 'string' && topic) this._topics.add(topic);
            });
        }

        this._openEventSource(hubUrl, data.token);
    }

    async _reconnect() {
        const hubUrl = document.querySelector('meta[name="mercure-hub"]')?.content;
        const tokenUrl = document.querySelector('meta[name="mercure-token-url"]')?.content;
        if (!hubUrl || !tokenUrl) return;

        let data;
        try {
            data = await fetch(tokenUrl, { credentials: 'same-origin' }).then(r => r.json());
        } catch {
            return;
        }
        if (!data || !data.token) return;

        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
        this._openEventSource(hubUrl, data.token);
    }

    _openEventSource(hubUrl, token) {
        const url = new URL(hubUrl);
        for (const topic of this._topics) {
            url.searchParams.append('topic', topic);
        }
        url.searchParams.set('authorization', token);
        const lastEventId = this._replayableId();
        if (lastEventId) {
            url.searchParams.set('lastEventID', lastEventId);
        }

        const es = new EventSource(url.toString());
        es.onmessage = (event) => this._dispatch(event);
        // ⚠️ The browser reconnects on its own and sends the last ID it received, whatever its age.
        // Past the replay bound, that reconnect would replay hours of history: close it and open a
        // fresh connection instead, which carries no ID.
        es.onerror = () => {
            if (es.readyState === EventSource.CONNECTING && this._lastEvent && !this._replayableId()) {
                es.close();
                this._eventSource = null;
                this._reconnect();
            }
        };
        this._eventSource = es;
    }

    /** The last ID if it is recent enough to be replayed from, otherwise null. */
    _replayableId() {
        if (!this._lastEvent) return null;

        return Date.now() - this._lastEvent.at <= this._replayMaxAgeMs() ? this._lastEvent.id : null;
    }

    _replayMaxAgeMs() {
        const seconds = Number(document.querySelector('meta[name="mercure-replay-max-age"]')?.content);

        return (Number.isFinite(seconds) && seconds >= 0 ? seconds : DEFAULT_REPLAY_MAX_AGE_SECONDS) * 1000;
    }

    _dispatch(event) {
        if (event.lastEventId) {
            this._lastEvent = { id: event.lastEventId, at: Date.now() };
            this._writeLastEvent(this._lastEvent);
        }

        let payload;
        try { payload = JSON.parse(event.data); } catch { return; }

        for (const handler of this._handlers) {
            try { handler(payload, event); } catch { /* ignore handler errors */ }
        }
    }

    /**
     * ⚠️ A value stored by an earlier version is a BARE ID, with no date: it cannot prove its age,
     * so it is treated as expired rather than replayed from.
     */
    _readLastEvent() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(LAST_EVENT_ID_KEY) || 'null');

            return saved && typeof saved.id === 'string' && typeof saved.at === 'number' ? saved : null;
        } catch {
            return null;
        }
    }

    _writeLastEvent(lastEvent) {
        try { sessionStorage.setItem(LAST_EVENT_ID_KEY, JSON.stringify(lastEvent)); } catch { /* ignore */ }
    }
}

const bus = new MercureBus();
export default bus;
