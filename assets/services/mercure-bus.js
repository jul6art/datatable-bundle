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
 * for org users) sets the subscriber cookie and returns `{ subscribed: [...] }`;
 * `subscribed[]` is the authoritative list used both as the token's grant and
 * as the EventSource subscription list. This avoids the drift that would happen
 * if Twig templates had to enumerate channels separately.
 *
 * A family of channels — every organisation's feed — is a URL Pattern (3.1):
 * the endpoint names them in `patterns` (`/organizations/:id/feed`), and the
 * bus listens to each with `match_urlpattern=`.
 *
 * Mercure protocol 1.0 (3.0): topics are matched exactly (`match=`), the
 * reconnection ID is `last_event_id`, and the token travels in the cookie only
 * — the `authorization` query parameter is gone (RFC 9700). The hub must be
 * served under the page's own host, or the cookie never reaches it.
 *
 * Additional topics can still be registered at runtime via `addTopic(topic)`
 * — the notification bell uses this to watch `/notifications/{userId}`
 * when its Stimulus values are only known at controller connect.
 *
 * Resilience:
 *   - Last-Event-ID is persisted in sessionStorage between navigations so
 *     the hub replays anything emitted during a BRIEF disconnect.
 *   - Native EventSource auto-reconnect handles transient network failures.
 *   - A connection the browser gives up on — the hub refused it (an expired
 *     subscriber cookie: 401) or answered anything but a stream — is opened
 *     again with a fresh token, backing off from 2 s to a minute. Without it,
 *     the feed of a page left open past the token's lifetime died at the first
 *     network drop, silently, until the page was reloaded.
 *
 * ⚠️ **A subscriber re-reads after any interruption** (3.4): `onResync(handler)`
 * runs once the feed is back after a drop, a refusal, a page restored from the
 * back/forward cache or a tab the browser froze — whatever was published in the
 * meantime may never arrive. A table reloads, a counter re-reads its page. The
 * handler is told `{ replayed }`: true when the hub was asked for everything
 * since the last event this tab saw, so the missed events SHOULD come as
 * ordinary messages — a subscriber that only reacts to one entity's events may
 * rely on them; one that shows a list re-reads anyway, history is not
 * guaranteed. The first connection of a page is not a resync: the page was
 * rendered just before.
 *
 *   const stop = mercureBus.onResync(({ replayed }) => this.reload());
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
const RETRY_MIN_MS = 2000;
const RETRY_MAX_MS = 60000;

class MercureBus {
    constructor() {
        this._eventSource = null;
        this._handlers = new Set();
        this._resyncHandlers = new Set();
        this._topics = new Set();
        this._patterns = new Set();
        this._connectPromise = null;
        this._initialized = false;
        // `{ id, at }` — the ID and when it was RECEIVED, so its age can be told.
        this._lastEvent = this._readLastEvent();
        // Set when the feed is interrupted, cleared when it is back and the subscribers were told.
        this._interrupted = false;
        this._retryDelay = 0;
        this._retryTimer = null;

        // A page restored from the back/forward cache, or a frozen tab that resumes, shows what it
        // showed when it left.
        window.addEventListener('pageshow', (event) => { if (event.persisted) this._wake(); });
        document.addEventListener('resume', () => this._wake());
    }

    onMessage(handler) {
        this._handlers.add(handler);
        this._ensureConnected();
        return () => { this._handlers.delete(handler); };
    }

    /** `handler({ replayed })` once the feed is back from an interruption — see the header. */
    onResync(handler) {
        this._resyncHandlers.add(handler);
        this._ensureConnected();
        return () => { this._resyncHandlers.delete(handler); };
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

        const data = await this._fetchToken(tokenUrl);
        if (!data) return;

        // The server is the single source of truth — add whatever the
        // token endpoint authorised for this user.
        data.subscribed.forEach(topic => {
            if (typeof topic === 'string' && topic) this._topics.add(topic);
        });
        this._addPatterns(data.patterns);

        this._openEventSource(hubUrl);
    }

    async _reconnect() {
        const hubUrl = document.querySelector('meta[name="mercure-hub"]')?.content;
        const tokenUrl = document.querySelector('meta[name="mercure-token-url"]')?.content;
        if (!hubUrl || !tokenUrl) return;

        const data = await this._fetchToken(tokenUrl);
        if (!data) return;
        this._addPatterns(data.patterns);

        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
        this._openEventSource(hubUrl);
    }

    /**
     * The token endpoint's answer — it also sets the subscriber cookie —, or null.
     *
     * ⚠️ A network failure is retried when no connection is left: the first one of the page, or a
     * connection the hub refused. A signed-out session is not — the endpoint redirects to the login
     * page, and no token will ever come from this page.
     */
    async _fetchToken(tokenUrl) {
        let data;
        try {
            const response = await fetch(tokenUrl, { credentials: 'same-origin' });
            if (response.redirected || response.status === 401 || response.status === 403) return null;
            data = await response.json();
        } catch {
            if (!this._eventSource) {
                this._interrupted = true;
                this._scheduleRetry();
            }
            return null;
        }

        return data && Array.isArray(data.subscribed) ? data : null;
    }

    _scheduleRetry() {
        clearTimeout(this._retryTimer);
        this._retryDelay = Math.min(this._retryDelay ? this._retryDelay * 2 : RETRY_MIN_MS, RETRY_MAX_MS);
        this._retryTimer = setTimeout(() => this._reconnect(), this._retryDelay);
    }

    /** Back from the back/forward cache, or from a freeze: re-read now, or once the feed reopens. */
    _wake() {
        if (!this._initialized) return;

        if (this._eventSource && this._eventSource.readyState !== EventSource.CLOSED) {
            this._resync({ replayed: false });
            return;
        }

        this._interrupted = true;
        this._eventSource = null;
        clearTimeout(this._retryTimer);
        this._reconnect();
    }

    _resync(info) {
        for (const handler of this._resyncHandlers) {
            try { handler(info); } catch { /* ignore handler errors */ }
        }
    }

    _openEventSource(hubUrl) {
        const url = new URL(hubUrl, window.location.href);
        for (const topic of this._topics) {
            url.searchParams.append('match', topic);
        }
        for (const pattern of this._patterns) {
            url.searchParams.append('match_urlpattern', pattern);
        }
        const lastEventId = this._replayableId();
        if (lastEventId) {
            url.searchParams.set('last_event_id', lastEventId);
        }

        // Whether a reconnection of THIS connection asks the hub for history: the ID it was opened
        // with stays in its URL, and the browser sends the last one it received.
        let replays = Boolean(lastEventId);

        // The token endpoint set the subscriber cookie: `withCredentials` sends it along.
        const es = new EventSource(url.toString(), { withCredentials: true });
        es.onmessage = (event) => {
            if (event.lastEventId) replays = true;
            this._dispatch(event);
        };
        es.onopen = () => {
            this._retryDelay = 0;
            if (!this._interrupted) return;

            this._interrupted = false;
            this._resync({ replayed: replays });
        };
        es.onerror = () => {
            this._interrupted = true;

            // ⚠️ The browser reconnects on its own and sends the last ID it received, whatever its
            // age. Past the replay bound, that reconnect would replay hours of history: close it and
            // open a fresh connection instead, which carries no ID.
            if (es.readyState === EventSource.CONNECTING && this._lastEvent && !this._replayableId()) {
                es.close();
                this._eventSource = null;
                this._reconnect();
                return;
            }

            // ⚠️ A connection the hub refused, or answered with anything but a stream, is CLOSED for
            // good: the browser never retries it. A new token, then a new connection.
            if (es.readyState === EventSource.CLOSED && this._eventSource === es) {
                this._eventSource = null;
                this._scheduleRetry();
            }
        };
        this._eventSource = es;
    }

    _addPatterns(patterns) {
        if (!Array.isArray(patterns)) return;
        patterns.forEach(pattern => {
            if (typeof pattern === 'string' && pattern) this._patterns.add(pattern);
        });
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
