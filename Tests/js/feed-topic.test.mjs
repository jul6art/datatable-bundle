// The organisation scope of a datatable's real-time feed, with and without an application's
// prefix on a shared hub (push-bundle 3.2).
//
// Run with Node alone, no dependency: `node --test Tests/js/*.mjs`.

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { feedMatchesCollection, isOrganizationFeedTopic } from '../../assets/services/feed-topic.js';

test('an organisation feed is one, prefixed or not', () => {
    assert.equal(isOrganizationFeedTopic('/organizations/7/feed'), true);
    assert.equal(isOrganizationFeedTopic('/shop/organizations/7/feed'), true);
    assert.equal(isOrganizationFeedTopic('/a/b/organizations/abc/feed'), true);
});

test('the admin and global feeds are not an organisation feed', () => {
    for (const topic of ['/admin/feed', '/global/feed', '/shop/admin/feed', '/platform/feed', '/organizations/feed', '/organizations/7/feed/extra', '', 'organizations/7/feed']) {
        assert.equal(isOrganizationFeedTopic(topic), false, topic);
    }
});

test('anything but a string is not a topic', () => {
    assert.equal(isOrganizationFeedTopic(undefined), false);
    assert.equal(isOrganizationFeedTopic(null), false);
    assert.equal(isOrganizationFeedTopic(7), false);
});

test('an event with an IRI matches the items of its collection, query string aside', () => {
    assert.equal(feedMatchesCollection('/api/work_orders', {type: 'WorkOrder', iri: '/api/work_orders/12'}), true);
    assert.equal(feedMatchesCollection('/api/users?organization=/api/organizations/5', {type: 'User', iri: '/api/users/12'}), true);
    assert.equal(feedMatchesCollection('/api/work_orders', {type: 'WorkOrder', iri: '/api/work_order_types/3'}), false);
});

// 3.4.1: `'work_orders'.startsWith('workorder')` was false — every `bulk` event of a type of two
// words or more was dropped, the table stayed stale.
test('a bulk event, which carries no IRI, matches its collection by type, whatever the case', () => {
    assert.equal(feedMatchesCollection('/api/work_orders', {type: 'WorkOrder', iri: null, action: 'bulk'}), true);
    assert.equal(feedMatchesCollection('/api/stock_levels', {type: 'StockLevel', iri: null}), true);
    assert.equal(feedMatchesCollection('/api/service_contracts?status=active', {type: 'ServiceContract'}), true);
    assert.equal(feedMatchesCollection('/api/users', {type: 'User', iri: null}), true);
});

test('a bulk event of another type, or of no type, matches nothing', () => {
    assert.equal(feedMatchesCollection('/api/work_orders', {type: 'Quote', iri: null}), false);
    assert.equal(feedMatchesCollection('/api/work_orders', {type: 'WorkOrderSignature', iri: null}), false);
    assert.equal(feedMatchesCollection('/api/work_orders', {iri: null}), false);
    assert.equal(feedMatchesCollection('/api/work_orders', {type: '', iri: null}), false);
});
