/**
 * Whether a feed topic is an organisation's: `/organizations/7/feed`, or the same topic in an
 * application's corner of a shared hub — `/cereezer/organizations/7/feed` (push-bundle 3.2,
 * `push.mercure.topic_prefix`).
 *
 * ⚠️ Not `startsWith('/organizations/')`: with a prefix, every organisation event failed it, and an
 * org-scoped table silently stopped reloading. The prefix is the application's business, not the
 * table's — any leading segments are accepted, the organisation segment is what counts.
 *
 * @param {string} topic
 * @returns {boolean}
 */
export function isOrganizationFeedTopic(topic) {
    return typeof topic === 'string' && /^(?:\/[^/]+)*?\/organizations\/[^/]+\/feed$/.test(topic);
}
