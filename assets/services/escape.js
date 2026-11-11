/**
 * Escaping for the HTML the table builds as strings.
 *
 * ⚠️ Every value that comes from a row — a name, a label, an IRI the resolver fetched — is data typed
 * by someone, and DataTables writes a cell with `innerHTML`. Rendered unescaped, a first name such as
 * `<img src=x onerror=…>` runs in the session of whoever opens the list.
 *
 * Pure functions on strings, with no DOM: the same output in the browser and under `node --test`.
 */

const ENTITIES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
};

/**
 * Text placed between tags, or inside a quoted attribute — the five characters cover both.
 *
 * `null` and `undefined` give an empty string: the caller decides what an absent value shows.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }

    // `\x22\x27` rather than the two quotes: a lone quote in a regular expression literal throws
    // core-bundle's `JsTranslationScanner` into string mode for the rest of the file.
    return String(value).replace(/[&<>\x22\x27]/g, (character) => ENTITIES[character]);
}

/**
 * A value placed inside a quoted attribute. Same escaping as {@see escapeHtml}: named apart so the
 * call site says where the value lands.
 *
 * ⚠️ It does not make a URL safe: a `javascript:` address escaped is still a `javascript:` address.
 * A link built from row data takes its prefix from the server and each row value through
 * {@see encodePathSegment}; a stored path for a `src` goes through {@see safeUrlPath}.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function escapeAttr(value) {
    return escapeHtml(value);
}

/**
 * A path segment taken from a row (an id, a uuid, an e-mail in a route), percent-encoded so it can
 * neither leave its segment (`/`, `?`, `#`) nor close the attribute it sits in.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function encodePathSegment(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return encodeURIComponent(String(value));
}

/**
 * A path stored by the application (an avatar, a thumbnail), kept only when it is a relative path
 * of plain characters. Anything else — a scheme, a `//host`, a quote, a backslash — gives `null`, and
 * the caller shows its fallback instead of the image.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function safeUrlPath(value) {
    // `//host/…` is an address on another host, not a path: refused before the leading `/` goes.
    if (typeof value !== 'string' || value === '' || value.startsWith('//')) {
        return null;
    }

    const path = value.replace(/^\//, '');

    return /^[A-Za-z0-9._~\-/%]+$/.test(path) && !path.includes('..') ? path : null;
}
