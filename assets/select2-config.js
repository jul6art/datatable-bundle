/**
 * What an application adds to a Select2 autocomplete request beyond the search term.
 *
 * The need is always the same shape and never the same vocabulary: a form publishes a value in its
 * dataset — the customer just picked, the warehouse selected above — and a picker further down has
 * to narrow its list by it, **live**, without being re-initialised when the parent changes.
 *
 * ```js
 * // assets/app.js, once
 * import { configureSelect2 } from '@jul6art/datatable-bundle/select2-config';
 *
 * configureSelect2([
 *     { datasetKey: 'eligibleCountry', queryKey: 'eligibleCountry[]', whenUrlIncludes: '/api/products' },
 * ]);
 * ```
 *
 * `datasetKey` is read on the closest `<form>` (`data-eligible-country` → `eligibleCountry`),
 * `queryKey` is what goes into the request, and `whenUrlIncludes` restricts the rule to the pickers
 * it concerns — without it the parameter would be appended to every autocomplete on the page,
 * including those whose endpoint has no such filter and answers an empty list.
 *
 * Declaring nothing is the normal case: most applications have no such coupling.
 */

/** @typedef {{ datasetKey: string, queryKey: string, whenUrlIncludes?: string }} LiveFormParam */

/** @type {LiveFormParam[]} */
let rules = [];

/**
 * @param {LiveFormParam[]} declared replaces the current set — an application declares once
 */
export function configureSelect2(declared) {
    rules = Array.isArray(declared) ? declared : [];
}

/** @returns {LiveFormParam[]} */
export function liveFormParams() {
    return rules;
}

/**
 * The query string of ONE autocomplete request — shared by the form picker and both filter builders
 * (the column filter and the mobile sheet), which were three copies of the same lines.
 *
 * `orderKey` asks for the suggestions in ascending order on that key (`order[<key>]=asc`). Without
 * it the collection answers in its own default order — the TABLE's, most often "newest first" —
 * and the user scrolls a list sorted by nothing they can see (cereezer report 2026-10-03, P2).
 * `null` (or `'none'` resolved by the caller) sends no order.
 *
 * @param {{ searchParam: string, term?: string|null, orderKey?: string|null }} options
 * @returns {Record<string, string|number>}
 */
export function ajaxQuery({ searchParam, term, orderKey }) {
    const query = { [searchParam]: term || '', size: 20 };

    if (orderKey) {
        query[`order[${orderKey}]`] = 'asc';
    }

    return query;
}

/**
 * The order key a picker resolves: the explicit one, else the displayed key; `'none'` opts out.
 *
 * @param {string|null|undefined} orderKey
 * @param {string|null|undefined} textKey
 * @returns {string|null}
 */
export function resolveOrderKey(orderKey, textKey) {
    if ('none' === orderKey) {
        return null;
    }

    return orderKey || textKey || null;
}
