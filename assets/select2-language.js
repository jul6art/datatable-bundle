import { t } from '@jul6art/core-bundle/i18n/registry';

/**
 * Select2's own messages, read from the catalogue like every other label of this bundle.
 *
 * ⚠️ **`language: 'fr'` translated nothing.** Select2 only knows a locale it has REGISTERED, and it
 * registers one when the project loads `select2/dist/js/i18n/<locale>.js` — which a project has
 * to know to do, after the jQuery global exists, and for each locale it serves. Two products out
 * of three did not, so "Please enter 1 or more characters" and "No results found" showed in
 * English under every autocomplete of a French back office; the third required two locale files
 * by hand. Select2 ships no Luxembourgish at all.
 *
 * Handing Select2 an OBJECT instead of a locale code removes the registration step: the messages
 * come from `datatable.select2.*` in the project's `javascript` domain, in whatever locales the
 * project declares — the same source as the DataTables strings (`getLanguageConfig()`).
 *
 * ```js
 * import { select2Language } from '@jul6art/datatable-bundle/select2-language';
 *
 * $(select).select2({ language: select2Language(), … });
 * ```
 *
 * ⚠️ Called at initialisation, not at import: the application registers its translator from its
 * entry point, and a module-level object would be built before that and hold raw keys.
 *
 * `%count%` is the count Select2 computes, substituted by the translator like any `%name%`
 * parameter of the catalogue.
 *
 * @returns {Record<string, (args?: object) => string>}
 */
export function select2Language() {
    const messages = {
        errorLoading: () => t('datatable.select2.error_loading'),
        inputTooLong: ({ input, maximum }) => t('datatable.select2.input_too_long', { '%count%': input.length - maximum }),
        inputTooShort: ({ input, minimum }) => t('datatable.select2.input_too_short', { '%count%': minimum - input.length }),
        loadingMore: () => t('datatable.select2.loading_more'),
        maximumSelected: ({ maximum }) => t('datatable.select2.maximum_selected', { '%count%': maximum }),
        noResults: () => t('datatable.select2.no_results'),
        searching: () => t('datatable.select2.searching'),
        removeAllItems: () => t('datatable.select2.remove_all_items'),
        removeItem: () => t('datatable.select2.remove_item'),
        search: () => t('datatable.select2.search'),
    };

    // ⚠️ A message the catalogue does not translate is LEFT OUT, so Select2 falls back to its
    // own (English, or a locale file the project registered) rather than printing
    // `datatable.select2.no_results` under the field: the translator returns the key itself
    // for a missing entry. A project that has not added the keys yet keeps what it had.
    return Object.fromEntries(
        Object.entries(messages).filter(([, message]) => !message(PROBE).startsWith('datatable.select2.'))
    );
}

/** Arguments shaped like those Select2 passes, to ask each message whether it is translated. */
const PROBE = { input: '', minimum: 1, maximum: 1 };
