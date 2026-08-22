/**
 * IRI Resolver — Singleton service for resolving JSON-LD IRI strings to objects.
 *
 * Uses in-memory cache + browser/Varnish HTTP cache (via Cache-Control headers).
 * No custom TTL — relies on API Platform cache headers per entity.
 *
 * Usage:
 *   import iriResolver from '../services/iri-resolver';
 *
 *   const name = await iriResolver.resolve('/api/organizations/5', 'name');
 *   const obj  = iriResolver.get('/api/organizations/5'); // sync cache read
 *   iriResolver.invalidate('/api/organizations/5');       // after Mercure update
 */

const cache = new Map();
const inflight = new Map();
const invalidated = new Set();

const iriResolver = {
    /**
     * Resolve an IRI to an object (async). Returns cached if available.
     * @param {string} iri - The IRI to resolve (e.g. "/api/organizations/5")
     * @param {string} [field] - Optional field to extract from resolved object
     * @returns {Promise<*>}
     */
    async resolve(iri, field) {
        const cached = cache.get(iri);
        if (cached !== undefined) {
            return field ? (cached?.[field] ?? null) : cached;
        }

        if (inflight.has(iri)) {
            const obj = await inflight.get(iri);
            return field ? (obj?.[field] ?? null) : obj;
        }

        const bypassCache = invalidated.has(iri);
        if (bypassCache) invalidated.delete(iri);

        const fetchUrl = bypassCache ? iri + (iri.includes('?') ? '&' : '?') + '_t=' + Date.now() : iri;

        const promise = fetch(fetchUrl, {
            headers: {
                'Accept': 'application/ld+json',
                'Authorization': window.jwtToken ? `Bearer ${window.jwtToken}` : '',
                ...(window.organizationSlug ? { 'X-ORGANIZATION': window.organizationSlug } : {}),
            },
        })
            .then(r => (r.ok ? r.json() : null))
            .then(obj => {
                cache.set(iri, obj);
                inflight.delete(iri);
                return obj;
            })
            .catch(() => {
                cache.set(iri, null);
                inflight.delete(iri);
                return null;
            });

        inflight.set(iri, promise);
        const result = await promise;
        return field ? (result?.[field] ?? null) : result;
    },

    /**
     * Resolve multiple IRIs concurrently.
     * @param {string[]} iris
     * @returns {Promise<void>}
     */
    async resolveMany(iris) {
        const unique = [...new Set(iris)].filter(iri => cache.get(iri) === undefined);
        if (unique.length === 0) return;
        await Promise.all(unique.map(iri => this.resolve(iri)));
    },

    /**
     * Synchronous cache read. Returns undefined if not cached.
     * @param {string} iri
     * @returns {object|null|undefined}
     */
    get(iri) {
        return cache.get(iri);
    },

    /**
     * Invalidate a single cached IRI (e.g. after Mercure update).
     * Also marks it for forced re-fetch (bypass browser HTTP cache).
     * @param {string} iri
     */
    invalidate(iri) {
        cache.delete(iri);
        invalidated.add(iri);
    },

    /**
     * Clear entire cache (e.g. on Turbo navigation).
     */
    clear() {
        cache.clear();
        inflight.clear();
        invalidated.clear();
    },
};

export default iriResolver;
