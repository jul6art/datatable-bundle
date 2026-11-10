// The escaping every renderer of the table relies on (audit 2026-11-10, S9).
//
// Run with Node alone, no dependency: `node --test Tests/js/`.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { escapeHtml, escapeAttr, encodePathSegment, safeUrlPath } from '../../assets/services/escape.js';

const PAYLOAD = '<img src=x onerror=alert(1)>';

test('escapeHtml leaves no tag a browser would parse', () => {
    const escaped = escapeHtml(PAYLOAD);

    assert.equal(escaped, '&lt;img src=x onerror=alert(1)&gt;');
    assert.ok(!escaped.includes('<'));
});

test('escapeHtml escapes the ampersand first, and both quotes', () => {
    assert.equal(escapeHtml(`Tom & "Jerry" l'ami`), 'Tom &amp; &quot;Jerry&quot; l&#39;ami');
    assert.equal(escapeHtml('&lt;'), '&amp;lt;');
});

test('escapeHtml prints what String() prints, and nothing for an absent value', () => {
    assert.equal(escapeHtml(42), '42');
    assert.equal(escapeHtml(['a', '<b>']), 'a,&lt;b&gt;');
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
});

test('escapeAttr closes no attribute', () => {
    const attribute = `title="${escapeAttr('" onmouseover="alert(1)')}"`;

    assert.equal(attribute, 'title="&quot; onmouseover=&quot;alert(1)"');
});

test('encodePathSegment keeps a value inside its segment', () => {
    assert.equal(encodePathSegment('12'), '12');
    assert.equal(encodePathSegment('a/b?c#d'), 'a%2Fb%3Fc%23d');
    assert.equal(encodePathSegment('jane+doe@example.test'), 'jane%2Bdoe%40example.test');
    assert.equal(encodePathSegment('"><script>'), '%22%3E%3Cscript%3E');
    assert.equal(encodePathSegment(null), '');
});

test('safeUrlPath keeps a stored relative path and refuses anything else', () => {
    assert.equal(safeUrlPath('uploads/avatars/jane-1a2b.webp'), 'uploads/avatars/jane-1a2b.webp');
    assert.equal(safeUrlPath('/uploads/avatars/jane.png'), 'uploads/avatars/jane.png');

    for (const hostile of ['javascript:alert(1)', '//evil.test/x.png', 'x.png" onerror="alert(1)', '../../etc/passwd', 'a\\b', '', null, 42]) {
        assert.equal(safeUrlPath(hostile), null, String(hostile));
    }
});
