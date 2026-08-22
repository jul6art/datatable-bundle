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
