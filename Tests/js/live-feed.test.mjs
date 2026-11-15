// A Mercure subscription of its own that comes back (3.4): `openLiveFeed` re-opens a connection
// the browser gave up on, and tells the page once the feed is back after any interruption.
//
// Run with Node alone, no dependency: `node --test Tests/js/*.mjs`. The browser is faked — an
// `EventSource` driven by hand, a `connect()` the test controls.

import { test } from 'node:test';
import assert from 'node:assert/strict';

let sources = [];

class FakeEventSource {
    static CONNECTING = 0;
    static OPEN = 1;
    static CLOSED = 2;

    constructor(url) {
        this.url = String(url);
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

    drop() {
        this.readyState = FakeEventSource.CONNECTING;
        this.onerror?.();
    }

    refuse() {
        this.readyState = FakeEventSource.CLOSED;
        this.onerror?.();
    }

    message(data) {
        this.onmessage?.({ data });
    }
}

globalThis.EventSource = FakeEventSource;
globalThis.window = new EventTarget();
globalThis.document = new EventTarget();

const { openLiveFeed } = await import('../../assets/services/live-feed.js');

const settle = () => new Promise((resolve) => setImmediate(resolve));

/** A feed whose `connect()` answers what `answer()` says, and records what the page is told. */
async function feed(answer = () => 'https://app.test/.well-known/mercure?match=%2Fme') {
    sources = [];
    const told = { messages: [], resyncs: 0, connects: 0 };
    const handle = openLiveFeed({
        connect: async () => {
            told.connects++;

            return answer();
        },
        onMessage: (event) => told.messages.push(event.data),
        onResync: () => told.resyncs++,
    });
    await settle();

    return { handle, told };
}

test('the first connection is not a resync, and messages reach the page', async () => {
    const { told } = await feed();
    sources[0].open();
    sources[0].message('{"unread":3}');

    assert.equal(told.resyncs, 0, 'the page was rendered just before');
    assert.deepEqual(told.messages, ['{"unread":3}']);
});

test('a drop the browser recovers from is a resync', async () => {
    const { told } = await feed();
    sources[0].open();

    sources[0].drop();
    assert.equal(told.resyncs, 0);
    sources[0].open();

    assert.equal(told.resyncs, 1);
    assert.equal(sources.length, 1, 'the browser reconnected the same EventSource');
});

test('a refusal is opened again with a fresh token, backing off, then the page re-reads', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { told } = await feed();
    sources[0].open();

    sources[0].refuse();
    t.mock.timers.tick(1999);
    await settle();
    assert.equal(told.connects, 1, 'not before two seconds');

    t.mock.timers.tick(1);
    await settle();
    assert.equal(told.connects, 2, 'connect() again: the token endpoint sets a new cookie');

    sources[1].refuse();
    t.mock.timers.tick(2000);
    await settle();
    assert.equal(sources.length, 2, 'refused again: the delay doubles');
    t.mock.timers.tick(2000);
    await settle();

    sources[2].open();
    assert.equal(told.resyncs, 1);
});

test('a connect() that throws is retried; one that returns null gives up', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let answer = () => { throw new TypeError('Failed to fetch'); };
    const { told } = await feed(() => answer());
    assert.equal(sources.length, 0);

    answer = () => 'https://app.test/.well-known/mercure?match=%2Fme';
    t.mock.timers.tick(2000);
    await settle();
    sources[0].open();
    assert.equal(told.resyncs, 1, 'whatever was published since the page was rendered may be lost');

    answer = () => null;
    sources[0].refuse();
    t.mock.timers.tick(2000);
    await settle();
    t.mock.timers.tick(60000);
    await settle();
    assert.equal(told.connects, 3, 'signed out: one try, and no more');
    assert.equal(sources.length, 1);
});

test('a page back from the back/forward cache re-reads at once', async () => {
    const { handle, told } = await feed();
    sources[0].open();

    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
    assert.equal(told.resyncs, 1);

    handle.close();
});

test('a frozen tab whose connection was closed opens a new one, then re-reads', async () => {
    const { handle, told } = await feed();
    sources[0].open();
    sources[0].close();

    document.dispatchEvent(new Event('resume'));
    await settle();
    assert.equal(sources.length, 2);
    sources[1].open();
    assert.equal(told.resyncs, 1);

    handle.close();
});

test('closed, the feed retries nothing and hears nothing more', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { handle, told } = await feed();
    sources[0].open();

    sources[0].refuse();
    handle.close();
    t.mock.timers.tick(60000);
    await settle();
    window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));

    assert.equal(told.connects, 1);
    assert.equal(told.resyncs, 0);
});
