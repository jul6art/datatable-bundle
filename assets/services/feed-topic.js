/**
 * Whether a feed topic is an organisation's: `/organizations/7/feed`, or the same topic in an
 * application's corner of a shared hub — `/shop/organizations/7/feed` (push-bundle 3.2,
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

/**
 * Whether a feed event concerns a table's collection (`/api/work_orders`).
 *
 * An event with an IRI matches when the IRI is one of the collection's items. Without one — a
 * `bulk` event never carries an IRI, nor does an entity API Platform does not expose — the event's
 * type is matched against the collection's last segment.
 *
 * ⚠️ Compared letters and digits only: the type is the class's short name (`WorkOrder`), the
 * segment API Platform's snake case (`work_orders`). Until 3.4.1 they were compared as written, and
 * `'work_orders'.startsWith('workorder')` is false — every `bulk` event of a type of two words or
 * more (a flush of ten rows or more, a bulk write announced by push-bundle ≥ 3.5) was dropped, and
 * the table stayed stale.
 *
 * @param {string} collection the collection's address, query string included or not
 * @param {{type?: unknown, iri?: unknown}} payload
 * @returns {boolean}
 */
export function feedMatchesCollection(collection, payload) {
    const base = String(collection).split('?')[0];
    if (typeof payload.iri === 'string' && payload.iri !== '') {
        return payload.iri.startsWith(base + '/') || payload.iri === base;
    }

    const letters = (value) => String(value ?? '').toLowerCase().replace(/[^a-z0-9]/g, '');
    const type = letters(payload.type);

    return type !== '' && letters(base.split('/').pop()).startsWith(type);
}
