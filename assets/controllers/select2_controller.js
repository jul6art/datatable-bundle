import { Controller } from '@hotwired/stimulus';

/**
 * Select2 Controller — Enhanced select inputs.
 */
export default class extends Controller {
    static values = {
        placeholder: { type: String, default: '' },
        url: { type: String, default: '' },
        idKey: { type: String, default: 'id' },
        textKey: { type: String, default: 'name' },
        // Query parameter name used for the search term. Defaults to
        // `textKey` (same value used for display). Override when the API
        // exposes the label under a different key than the search field —
        // e.g. User entity displays `fullName` but searches via `search`
        // (OrSearchFilter over email/firstName/lastName).
        searchKey: { type: String, default: '' },
        // Optional secondary field appended in parentheses to each result
        // label, e.g. show the SKU next to the product name so a search by
        // reference / barcode makes the matching code visible (P-F, rapport
        // 2026-05-31-2). Empty = label is the `textKey` alone.
        secondaryKey: { type: String, default: '' },
        allowClear: { type: Boolean, default: true },
        minimumInputLength: { type: Number, default: 0 },
        // P5 (rapport 2026-05-17-2) — `allowTags: true` allows entering
        // a free value that is not in the AJAX results (typical for
        // `carrier`: combo with existing "DHL / UPS / Chronopost" plus
        // a custom value for a new carrier).
        allowTags: { type: Boolean, default: false },
        // Dependent picker (rapport 2026-06-13-2 § P1) — when set, the AJAX
        // search appends the LIVE value of a sibling select (matched by the
        // `dependsOn` CSS selector inside the same form) under the
        // `dependsParam` query key, so e.g. the task list is filtered by the
        // currently selected project. Changing the parent clears this select.
        dependsOn: { type: String, default: '' },
        dependsParam: { type: String, default: '' },
    };

    connect() {
        this._waitForSelect2(() => this._init());
    }

    disconnect() {
        if (this._onParentChange) {
            this._dependsOnElement()?.removeEventListener('change', this._onParentChange);
            this._onParentChange = null;
        }
        if (!window.jQuery) return;
        const $el = window.jQuery(this.element);
        if ($el.data('select2')) {
            $el.select2('destroy');
        }
    }

    _waitForSelect2(callback) {
        if (window.jQuery && window.jQuery.fn.select2) {
            callback();
            return;
        }
        this._waitInterval = setInterval(() => {
            if (window.jQuery && window.jQuery.fn.select2) {
                clearInterval(this._waitInterval);
                callback();
            }
        }, 50);
    }

    _init() {
        const $el = window.jQuery(this.element);

        const config = {
            placeholder: this.placeholderValue || null,
            allowClear: this.allowClearValue,
            width: '100%',
            theme: 'default',
            language: document.documentElement.lang || 'fr',
            // P5 (rapport 2026-05-17-2) — tags: true autorise les
            // valeurs libres (pour les combos AJAX + free-text mix
            // comme le champ `carrier` du BL).
            tags: this.allowTagsValue,
        };

        if (this.urlValue) {
            config.minimumInputLength = this.minimumInputLengthValue || 1;
            config.ajax = {
                url: this.urlValue,
                dataType: 'json',
                delay: 300,
                headers: {
                    'Accept': 'application/ld+json',
                    'Authorization': window.jwtToken ? `Bearer ${window.jwtToken}` : undefined,
                    ...(window.organizationSlug ? { 'X-ORGANIZATION': window.organizationSlug } : {}),
                },
                data: (params) => {
                    const query = {};
                    const searchParam = this.searchKeyValue || this.textKeyValue;
                    query[searchParam] = params.term || '';
                    query.size = 20;
                    // Dynamic customer-country eligibility (rapport 2026-06-06-4):
                    // a parent document form publishes the selected customer's
                    // ISO country in `data-eligible-country`; product pickers add
                    // it as a SECOND `eligibleCountry[]` so the list also respects
                    // the customer's allowed/forbidden countries (the org country
                    // is already baked into the base URL). Read LIVE per search —
                    // no re-init needed when the customer changes.
                    const extraCountry = this.element.closest('form')?.dataset?.eligibleCountry;
                    if (extraCountry && this.urlValue.includes('/api/products')) {
                        query['eligibleCountry[]'] = extraCountry;
                    }
                    // Dependent picker: filter by the live value of the parent select.
                    if (this.dependsOnValue && this.dependsParamValue) {
                        const parentValue = this._dependsOnElement()?.value;
                        if (parentValue) query[this.dependsParamValue] = parentValue;
                    }
                    return query;
                },
                processResults: (data) => {
                    const items = data.member || data['hydra:member'] || data.results || data || [];
                    return {
                        results: items.map(item => ({
                            id: item[this.idKeyValue] ?? item.id,
                            text: this._formatText(item),
                        }))
                    };
                },
                cache: true,
            };
        }

        $el.select2(config);

        $el.on('select2:select select2:unselect', () => {
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Dependent picker: when the parent select changes, clear this one so a
        // value belonging to the previous parent (e.g. a task of another
        // project) cannot stay selected. Cf. rapport 2026-06-13-2 § P1.
        if (this.dependsOnValue) {
            const parent = this._dependsOnElement();
            if (parent) {
                this._onParentChange = () => $el.val(null).trigger('change');
                parent.addEventListener('change', this._onParentChange);
            }
        }
    }

    _dependsOnElement() {
        return this.element.closest('form')?.querySelector(this.dependsOnValue) ?? null;
    }

    /**
     * Builds a result label: the `textKey` value, optionally followed by the
     * `secondaryKey` value in parentheses (e.g. "Widget A (SKU-001)") so a
     * reference / barcode match is visible. Cf. P-F (rapport 2026-05-31-2).
     */
    _formatText(item) {
        const base = item[this.textKeyValue] ?? item.name ?? String(item.id);
        const secondary = this.secondaryKeyValue ? item[this.secondaryKeyValue] : null;
        return secondary ? `${base} (${secondary})` : base;
    }
}
