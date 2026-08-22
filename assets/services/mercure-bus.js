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
 *     the hub replays anything emitted during a brief disconnect.
 *   - Native EventSource auto-reconnect handles transient network failures.
 */

const LAST_EVENT_ID_KEY = 'mercure_last_event_id';

class MercureBus {
    constructor() {
        this._eventSource = null;
        this._handlers = new Set();
        this._topics = new Set();
        this._connectPromise = null;
        this._initialized = false;
        this._lastEventId = this._readLastEventId();
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
        if (this._lastEventId) {
            url.searchParams.set('lastEventID', this._lastEventId);
        }

        const es = new EventSource(url.toString());
        es.onmessage = (event) => this._dispatch(event);
        this._eventSource = es;
    }

    _dispatch(event) {
        if (event.lastEventId) {
            this._lastEventId = event.lastEventId;
            this._writeLastEventId(event.lastEventId);
        }

        let payload;
        try { payload = JSON.parse(event.data); } catch { return; }

        for (const handler of this._handlers) {
            try { handler(payload, event); } catch { /* ignore handler errors */ }
        }
    }

    _readLastEventId() {
        try { return sessionStorage.getItem(LAST_EVENT_ID_KEY) || null; } catch { return null; }
    }

    _writeLastEventId(id) {
        try { sessionStorage.setItem(LAST_EVENT_ID_KEY, id); } catch { /* ignore */ }
    }
}

const bus = new MercureBus();
export default bus;
