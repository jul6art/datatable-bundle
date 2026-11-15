// The Mercure bus after an interruption (3.4): a subscriber re-reads once the feed is back, and a
// connection the browser gives up on is opened again with a fresh token.
//
// Run with Node alone, no dependency: `node --test Tests/js/*.mjs`. The browser is faked — an
// `EventSource` driven by hand, the two meta tags, a token endpoint — and every case imports its
// own bus: the module is a singleton.

import { test, mock } from 'node:test';
import assert from 'node:assert/strict';

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    constructor(url) {
        this.url = new URL(url);
        this.readyState = FakeEventSource.CONNECTING;
        sources.push(this);
    }

    close() {
        this.readyState = FakeEventSource.CLOSED;
    }

    open() {
        this.readyState = FakeEventSource.OPEN;
        this.onopen?.();
    }

    /** A network drop: the browser reconnects on its own. */
    drop() {
        this.readyState = FakeEventSource.CONNECTING;
        this.onerror?.();
    }

    /** A refusal (an expired cookie, 401): closed for good. */
    refuse() {
        this.readyState = FakeEventSource.CLOSED;
        this.onerror?.();
    }

    message(payload, id = '') {
        this.onmessage?.({ data: JSON.stringify(payload), lastEventId: id });
    }
}

let sources = [];
let tokenCalls = 0;
let tokenResponse = () => ({ redirected: false, status: 200, json: async () => ({ subscribed: ['/feed'] }) });
let instance = 0;

const META = {
    'meta[name="mercure-hub"]': { content: 'https://app.test/.well-known/mercure' },
    'meta[name="mercure-token-url"]': { content: '/admin/mercure-token' },
};

/** Lets the token request and its `json()` settle. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

async function freshBus({ stored = null } = {}) {
    sources = [];
    tokenCalls = 0;
    tokenResponse = () => ({ redirected: false, status: 200, json: async () => ({ subscribed: ['/feed'] }) });

    const storage = new Map(stored ? [['mercure_last_event_id', JSON.stringify(stored)]] : []);
    globalThis.sessionStorage = { getItem: (key) => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) };
    globalThis.window = Object.assign(new EventTarget(), { location: { href: 'https://app.test/admin' } });
    globalThis.document = Object.assign(new EventTarget(), { querySelector: (selector) => META[selector] ?? null });
    globalThis.EventSource = FakeEventSource;
    globalThis.fetch = async () => {
        tokenCalls++;

        return tokenResponse();
    };

    instance++;
    const { default: bus } = await import(`../../assets/services/mercure-bus.js?case=${instance}`);

    return bus;
}

/** A bus with a resync recorder, its first connection open. */
async function connected(options) {
    const bus = await freshBus(options);
    const resyncs = [];
    bus.onMessage(() => {});
    bus.onResync((info) => resyncs.push(info));
    await settle();
    sources[0].open();

    return { bus, resyncs };
}

test('the first connection of a page is not a resync', async () => {
    const { resyncs } = await connected();

    assert.equal(sources.length, 1);
    assert.deepEqual(resyncs, [], 'the page was rendered just before');
});

test('a drop the browser recovers from is a resync, told whether the hub was asked for history', async () => {
    const { resyncs } = await connected();

    sources[0].drop();
    assert.deepEqual(resyncs, [], 'nothing before the feed is back');
    sources[0].open();
    assert.deepEqual(resyncs, [{ replayed: false }], 'no event seen yet: nothing to replay from');

    sources[0].message({ type: 'Project' }, 'urn:uuid:1');
    sources[0].drop();
    sources[0].open();
    assert.deepEqual(resyncs[1], { replayed: true }, 'the browser sends the last ID it received');
    assert.equal(sources.length, 1, 'the browser reconnected: no second connection');
});

test('a connection the hub refuses is opened again with a fresh token, backing off', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { resyncs } = await connected();

    sources[0].refuse();
    t.mock.timers.tick(1999);
    await settle();
    assert.equal(sources.length, 1, 'not before two seconds');

    t.mock.timers.tick(1);
    await settle();
    assert.equal(tokenCalls, 2, 'a new token, which sets a new cookie');
    assert.equal(sources.length, 2);

    sources[1].refuse();
    t.mock.timers.tick(2000);
    await settle();
    assert.equal(sources.length, 2, 'refused again: the delay doubles');
    t.mock.timers.tick(2000);
    await settle();
    assert.equal(sources.length, 3);

    sources[2].open();
    assert.deepEqual(resyncs, [{ replayed: false }], 'back at last: the subscribers re-read, once');
});

test('a signed-out session is not retried', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    await connected();
    tokenResponse = () => ({ redirected: true, status: 200, json: async () => { throw new SyntaxError('the login page'); } });

    sources[0].refuse();
    t.mock.timers.tick(2000);
    await settle();
    t.mock.timers.tick(60000);
    await settle();

    assert.equal(tokenCalls, 2, 'one try, answered by the login page, and no more');
    assert.equal(sources.length, 1);
});

test('a token endpoint out of reach at the first connection is retried, and the page then re-reads', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const bus = await freshBus();
    const resyncs = [];
    tokenResponse = () => { throw new TypeError('Failed to fetch'); };
    bus.onResync((info) => resyncs.push(info));
    await settle();
    assert.equal(sources.length, 0);

    tokenResponse = () => ({ redirected: false, status: 200, json: async () => ({ subscribed: ['/feed'] }) });
    t.mock.timers.tick(2000);
    await settle();
    sources[0].open();

    assert.deepEqual(resyncs, [{ replayed: false }], 'whatever was published since the page was rendered may be lost');
});

test('a stale ID is not replayed from, and the fresh connection is a resync', async () => {
    const recent = await freshBus({ stored: { id: 'urn:uuid:9', at: Date.now() } });
    recent.onMessage(() => {});
    await settle();
    assert.equal(sources[0].url.searchParams.get('last_event_id'), 'urn:uuid:9');

    const { resyncs } = await connected({ stored: { id: 'urn:uuid:9', at: Date.now() - 600_000 } });
    assert.equal(sources[0].url.searchParams.get('last_event_id'), null, 'ten minutes old: past the bound');

    sources[0].message({ type: 'Project' }, 'urn:uuid:10');
    sources[0].drop();
    sources[0].open();
    assert.deepEqual(resyncs, [{ replayed: true }]);
});

test('a page back from the back/forward cache re-reads at once', async () => {
    const { resyncs } = await connected();

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: false }));
    assert.deepEqual(resyncs, [], 'an ordinary load is not a return');

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
    assert.deepEqual(resyncs, [{ replayed: false }]);
});

test('a frozen tab whose connection was closed opens a new one, then re-reads', async () => {
    const { resyncs } = await connected();
    sources[0].close();

    document.dispatchEvent(new Event('resume'));
    await settle();
    assert.equal(sources.length, 2);
    assert.deepEqual(resyncs, [], 'not before the feed is back');

    sources[1].open();
    assert.deepEqual(resyncs, [{ replayed: false }]);
});

test('a topic added at run time reconnects without a resync', async () => {
    const { bus, resyncs } = await connected();

    bus.addTopic('/notifications/7');
    await settle();
    sources[1].open();

    assert.equal(sources[0].readyState, FakeEventSource.CLOSED);
    assert.ok(sources[1].url.searchParams.getAll('match').includes('/notifications/7'));
    assert.deepEqual(resyncs, [], 'nothing was interrupted');
});

test('an unsubscribed handler hears nothing more', async () => {
    const bus = await freshBus();
    const heard = [];
    const stop = bus.onResync(() => heard.push('resync'));
    await settle();
    sources[0].open();

    stop();
    sources[0].drop();
    sources[0].open();

    assert.deepEqual(heard, []);
});

mock.reset();
