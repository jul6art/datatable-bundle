// The organisation scope of a datatable's real-time feed, with and without an application's
// prefix on a shared hub (push-bundle 3.2).
//
// Run with Node alone, no dependency: `node --test Tests/js/*.mjs`.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { isOrganizationFeedTopic } from '../../assets/services/feed-topic.js';

test('an organisation feed is one, prefixed or not', () => {
    assert.equal(isOrganizationFeedTopic('/organizations/7/feed'), true);
    assert.equal(isOrganizationFeedTopic('/cereezer/organizations/7/feed'), true);
    assert.equal(isOrganizationFeedTopic('/a/b/organizations/abc/feed'), true);
});

test('the admin and global feeds are not an organisation feed', () => {
    for (const topic of ['/admin/feed', '/global/feed', '/cereezer/admin/feed', '/platform/feed', '/organizations/feed', '/organizations/7/feed/extra', '', 'organizations/7/feed']) {
        assert.equal(isOrganizationFeedTopic(topic), false, topic);
    }
});

test('anything but a string is not a topic', () => {
    assert.equal(isOrganizationFeedTopic(undefined), false);
    assert.equal(isOrganizationFeedTopic(null), false);
    assert.equal(isOrganizationFeedTopic(7), false);
});
