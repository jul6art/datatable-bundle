/**
 * A Mercure subscription of its own that comes back (3.4).
 *
 * The shared bus (`services/mercure-bus.js`) is the way to listen: one `EventSource` per page, the
 * topics the token endpoint grants. Some pages cannot use it — a public page with its own token
 * endpoint, a family of channels the bus does not ask for — and opened their own `EventSource`.
 * Two things went wrong there, silently, until the page was reloaded:
 *
 * - **A refusal is final.** An `EventSource` the hub refuses (the subscriber cookie expired: 401)
 *   or answers with anything but a stream is CLOSED, and the browser never retries it. A page left
 *   open past the token's lifetime lost its live feed at the first network drop.
 * - **What was published during a drop is lost**, unless the hub replays it — which needs an ID the
 *   page rarely has. The page went on showing what it showed before.
 *
 * This opens the subscription again with a fresh token, backing off from 2 s to a minute, and tells
 * the page once the feed is back after any interruption — a drop, a refusal, a return from the
 * back/forward cache, a frozen tab that resumes — so that it re-reads what it shows.
 *
 * ```js
 * import { openLiveFeed } from '@jul6art/datatable-bundle/services/live-feed';
 *
 * this.feed = openLiveFeed({
 *     // Asks the token endpoint — which sets the subscriber cookie — and returns the hub URL with
 *     // its `match=` parameters. Throw to be retried (the network, a 5xx); return null to give up
 *     // (signed out, nothing to listen to).
 *     connect: async () => { … },
 *     onMessage: (event) => this.update(JSON.parse(event.data)),
 *     onResync: () => this.reread(),
 * });
 *
 * this.feed.close(); // in disconnect()
 * ```
 *
 * The first connection is not a resync: the page was rendered just before.
 */

const RETRY_MIN_MS = 2000;
const RETRY_MAX_MS = 60000;

/**
 * @param {{ connect: () => Promise<string|URL|null>, onMessage: (event: MessageEvent) => void, onResync?: () => void }} options
 * @returns {{ close: () => void }}
 */
export function openLiveFeed({ connect, onMessage, onResync = () => {} }) {
    let source = null;
    let interrupted = false;
    let closed = false;
    let delay = 0;
    let timer = null;

    const retry = () => {
        clearTimeout(timer);
        delay = Math.min(delay ? delay * 2 : RETRY_MIN_MS, RETRY_MAX_MS);
        timer = setTimeout(open, delay);
    };

    async function open() {
        let url;
        try {
            url = await connect();
        } catch {
            if (!closed) {
                interrupted = true;
                retry();
            }
            return;
        }
        if (closed || !url) return;

        source?.close();
        const es = new EventSource(url, { withCredentials: true });
        es.onmessage = (event) => {
            try { onMessage(event); } catch { /* one bad message does not end the feed */ }
        };
        es.onopen = () => {
            delay = 0;
            if (!interrupted) return;

            interrupted = false;
            onResync();
        };
        es.onerror = () => {
            interrupted = true;

            // ⚠️ Refused, or answered with anything but a stream: CLOSED for good, the browser
            // never retries. A drop leaves it CONNECTING — the browser is already on it.
            if (es.readyState === EventSource.CLOSED && source === es) {
                source = null;
                retry();
            }
        };
        source = es;
    }

    // Back from the back/forward cache, or from a freeze: re-read now, or once the feed reopens.
    const wake = () => {
        if (closed) return;

        if (source && source.readyState !== EventSource.CLOSED) {
            onResync();
            return;
        }

        interrupted = true;
        source = null;
        clearTimeout(timer);
        open();
    };
    const onPageShow = (event) => { if (event.persisted) wake(); };

    window.addEventListener('pageshow', onPageShow);
    document.addEventListener('resume', wake);
    open();

    return {
        close() {
            closed = true;
            clearTimeout(timer);
            source?.close();
            source = null;
            window.removeEventListener('pageshow', onPageShow);
            document.removeEventListener('resume', wake);
        },
    };
}
