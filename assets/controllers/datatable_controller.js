import { Controller } from '@hotwired/stimulus';
import { useTranslatable, translatableValues } from '../mixins/translatable';
import { useBlockable } from '../mixins/blockable';
import iriResolver from '../services/iri-resolver';
import mercureBus from '../services/mercure-bus';
import { getRegisteredRenderers } from '../renderers';
import { installActionsDropdown } from '../services/dropdown';

/**
 * The server-driven table: pagination, sorting, search, filters, per-row and bulk actions, live
 * refresh over Mercure — all against an API Platform collection.
 *
 * Everything it draws comes from three values a `*DataTableConfigProvider` produced server-side
 * and the template serialised into data attributes: `columns`, `filters`, `actions`. Nothing about
 * a particular entity is written here.
 *
 * ```twig
 * <table data-controller="{{ datatable_stimulus() }}"
 *        data-{{ datatable_stimulus() }}-api-url-value="{{ path('_api_/users{._format}_get_collection') }}"
 *        data-{{ datatable_stimulus() }}-columns-value="{{ columns_config|json_encode|e('html_attr') }}"
 *        data-{{ datatable_stimulus() }}-actions-value="{{ actions_config|json_encode|e('html_attr') }}"
 *        data-{{ datatable_stimulus() }}-filters-value="{{ filters_config|json_encode|e('html_attr') }}"
 *        {{ include('@Datatable/datatable/_csrf.html.twig') }}
 *        {{ include('@Datatable/datatable/_translations.html.twig') }}>
 * ```
 *
 * ## What the application has to provide
 *
 * - `window.DataTable` — DataTables 2.x with its Responsive plugin. Loaded by the application's
 *   entry point, not imported here, so a page with no table pays nothing for it.
 * - three sibling Stimulus controllers this one's markup names: `ui--modal` (the confirmation
 *   dialog of a per-row action), `ui--tooltip` (the label of an icon button) and `ui--select2`
 *   (the autocomplete of an API filter). All three ship with this bundle; register them under
 *   those identifiers.
 * - the badge renderers of its own domain, through `registerRenderers()` — see `../renderers`.
 *
 * ## Filters
 *
 * ```js
 * [
 *   { column: 'isActive', type: 'static', param: 'isActive', placeholder: '…',
 *     options: [{ value: 'true', label: '…' }, { value: 'false', label: '…' }] },
 *   { column: 'organization', type: 'api', param: 'organization', placeholder: '…',
 *     url: '/api/organizations', textKey: 'name', idKey: 'id', searchKey: 'search' },
 *   { column: 'createdAt', type: 'daterange', param: 'createdAt', granularity: 'datetime' },
 * ]
 * ```
 */
export default class extends Controller {
    static values = {
        ...translatableValues,
        apiUrl: String,
        columns: Array,
        actions: Array,
        actionsDisplay: { type: String, default: 'inline' },
        searchEnabled: { type: Boolean, default: true },
        filters: { type: Array, default: [] },
        searchableFields: { type: Array, default: [] },
        defaultOrder: { type: Array, default: [] },
        pageLength: { type: Number, default: 25 },
        language: { type: String, default: 'fr' },
        userLinkPrefix: { type: String, default: '/admin/users' },
        bulkCsrf: { type: String, default: '' },
        singleCsrf: { type: String, default: '' },
        // Per-user preferences (column visibility + order, saved views). Empty URL = feature off,
        // which is the default: the partial that fills these is included table by table.
        preferencesUrl: { type: String, default: '' },
        preferencesCsrf: { type: String, default: '' },
        countryNames: { type: Object, default: {} },
        customRoleLabels: { type: Object, default: {} },
        // Row field naming the record a confirm modal is about. Optional: when
        // empty, `_rowSubject()` probes the usual label fields. Set it only when
        // the right label is not among them (rapport 2026-08-05 § P2).
        subjectField: { type: String, default: '' },
    };

    connect() {
        // Le markup des actions en menu appelle `window._toggleDtDropdown` : la fonction est
        // installée ici, sinon le bouton ⋮ rend et ne fait rien.
        installActionsDropdown();
        useTranslatable(this);
        useBlockable(this);
        this._bulkSelection = new Set();
        this._bulkActions = (this.actionsValue || []).filter(a => a.bulk && a.bulkRoute);

        // ⚠️ The three below are seeded with `??=`, never `=`, and that is not a style choice.
        //
        // `connect()` runs a SECOND time on this very instance: building the DataTable wraps the
        // `<table>` in its own container, and a DOM re-insertion makes Stimulus disconnect then
        // reconnect the controller. Anything assigned here unconditionally is therefore wiped
        // AFTER the boot has filled it — and both times it happened, the symptom pointed somewhere
        // else entirely:
        //
        //   - `_columnPrefs` reset → the columns were right (they are baked in before the wrap) but
        //     the filter row, built later from `initComplete`, sat one cell out of line under the
        //     wrong headers. It read as a filter bug;
        //   - `_activeFilters` reset → the first request carried the default view's filters, so the
        //     rows were right, while the filter widgets showed nothing and no view looked active.
        //     It read as a rendering bug.
        //
        // The seed is still needed: the early return below skips the boot entirely on an
        // already-initialised table, and the Mercure path that follows reads all three.
        this._activeFilters ??= {};
        this._columnPrefs ??= this._defaultColumnPrefs();
        this._views ??= [];

        if (this.element.dataset.datatableInitialized) {
            this._subscribeMercure();
            return;
        }

        this._boot();
    }

    /**
     * The preferences are fetched BEFORE the table is built, not applied to it afterwards.
     *
     * Column order and visibility are baked into the column definitions DataTables receives, so
     * reading them late would mean building the table twice and firing two AJAX calls — the second
     * one visibly replacing the first. The wait costs one request against a route that returns a
     * single row; a table with no preferences configured never makes it.
     */
    async _boot() {
        await this._loadPreferences();

        // `disconnect()` may have run while the request was in flight — a Turbo navigation, a modal
        // closing. Building a table on a detached element leaves listeners nothing can remove.
        if (!this.element.isConnected) return;

        if (window.DataTable) {
            this.initializeDataTable();
            this.element.dataset.datatableInitialized = true;
            this._subscribeMercure();
        } else {
            this._waitForDataTable();
        }
    }

    _waitForDataTable() {
        const interval = setInterval(() => {
            if (window.DataTable) {
                clearInterval(interval);
                this.initializeDataTable();
                this.element.dataset.datatableInitialized = true;
                this._subscribeMercure();
            }
        }, 50);
    }

    disconnect() {
        this.unblockAll();
        this._destroyFilters();
        this._removePanelDismiss();
        this._unsubscribeMercure();
        if (this._prefsSaveTimer) {
            clearTimeout(this._prefsSaveTimer);
            this._prefsSaveTimer = null;
        }
        if (this._onWindowResize) {
            window.removeEventListener('resize', this._onWindowResize);
            this._onWindowResize = null;
        }
        if (this._resizeRaf) {
            cancelAnimationFrame(this._resizeRaf);
            this._resizeRaf = null;
        }
    }

    // ── Per-user preferences (columns + saved views) ────────────

    /**
     * Whether this table was given somewhere to save preferences. The whole feature keys off one
     * data attribute, so a table that does not include the partial pays nothing: no request, no
     * buttons, no code path.
     */
    get _hasPreferences() {
        return this.preferencesUrlValue !== '';
    }

    /**
     * The declared columns in the user's order. ALL of them, including the ones the user hid —
     * visibility is a flag on the DataTables column definition, so hiding one must not change any
     * index. Everything that maps a DataTables column index back to a descriptor (`meta.col`, the
     * sort field, the filter row, the cards) reads this list.
     */
    get _columns() {
        const byKey = new Map(this.columnsValue.map(col => [col.data, col]));

        return (this._columnPrefs || [])
            .map(pref => byKey.get(pref.key))
            .filter(Boolean);
    }

    /** The subset actually on screen — used where the reader sees columns rather than indexes. */
    get _visibleColumns() {
        return this._columns.filter(col => this._isColumnVisible(col.data));
    }

    _isColumnVisible(key) {
        const pref = (this._columnPrefs || []).find(entry => entry.key === key);

        return pref ? pref.visible : true;
    }

    /**
     * Everything declared, in the order the provider wrote it — visible unless the column asked not
     * to be.
     *
     * `hidden: true` is how a table offers a column without showing it: it is in the picker, absent
     * from the first paint, one tick away. It is honoured ONLY when this table has preferences —
     * without a picker a hidden column would be unreachable, so the flag is ignored and the column
     * shows. That is the lesser surprise, and it means a provider can declare `hidden` before its
     * template opts in.
     */
    _defaultColumnPrefs() {
        return this.columnsValue.map(col => ({
            key: col.data,
            visible: !(this._hasPreferences && col.hidden === true),
        }));
    }

    /**
     * Reads the stored preferences, and never lets them stop the table from rendering: a 404, a
     * 403 on an expired session, a network blip all fall back to the declared columns. A column
     * layout is a convenience; a list of products is the page.
     */
    async _loadPreferences() {
        this._columnPrefs = this._defaultColumnPrefs();
        this._views = [];
        this._activeViewId = null;
        this._preferredSort = null;

        if (!this._hasPreferences) return;

        try {
            const response = await fetch(this.preferencesUrlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            this._adoptPreferences(await response.json());
        } catch (e) {
            // Deliberately silent: see above.
        }
    }

    /**
     * Reconciles what was STORED with what the table DECLARES today.
     *
     * The server bounds the blob but cannot know a table's columns — one route serves them all —
     * so the vocabulary is settled here, where the declared columns are in hand. A key that no
     * longer exists is dropped; a column added since the last save is appended, visible, at the
     * end. Both cases are what happens every time a `*DataTableConfigProvider` is edited, so
     * neither may lose the rest of the layout.
     */
    _adoptPreferences(prefs) {
        if (!prefs || typeof prefs !== 'object') return;

        const declared = new Map(this.columnsValue.map(col => [col.data, col]));
        const stored = Array.isArray(prefs.columns) ? prefs.columns : [];
        const kept = stored.filter(pref => pref && declared.has(pref.key));
        const seen = new Set(kept.map(pref => pref.key));

        // A column DECLARED SINCE the last save is appended with the default the provider asked
        // for, not with `visible: true`. Without this, shipping a batch of `hidden` columns would
        // widen the table for every user who already had preferences stored — precisely the people
        // who had arranged it on purpose.
        const declaredDefaults = new Map(this._defaultColumnPrefs().map(pref => [pref.key, pref.visible]));

        this._columnPrefs = [
            ...kept.map(pref => ({ key: pref.key, visible: pref.visible !== false })),
            ...this.columnsValue
                .filter(col => !seen.has(col.data))
                .map(col => ({ key: col.data, visible: declaredDefaults.get(col.data) !== false })),
        ];

        // Never leave a table with no column at all: a stored blob that hid everything (an older
        // version, a hand-edited row) would render a header of nothing but the actions menu.
        if (!this._columnPrefs.some(pref => pref.visible)) {
            this._columnPrefs = this._columnPrefs.map(pref => ({ ...pref, visible: true }));
        }

        this._views = Array.isArray(prefs.views) ? prefs.views.filter(view => view && view.id && view.name) : [];
        this._preferredSort = prefs.sort && prefs.sort.key ? prefs.sort : null;
    }

    /**
     * The exact shape the endpoint interprets — the server sanitises, it does not guess.
     *
     * `sortOverride` exists for the one caller that has already changed the column order in memory:
     * `_currentSort()` resolves the LIVE DataTables order index through the column list, so once a
     * reorder has been spliced in, that index points at a different column. See `_moveColumn`.
     */
    _preferencePayload(sortOverride = null) {
        return {
            columns: (this._columnPrefs || []).map(pref => ({ key: pref.key, visible: pref.visible })),
            sort: sortOverride ?? this._currentSort(),
            views: this._views || [],
        };
    }

    _currentSort() {
        const keys = this._currentOrderKeys();

        return keys.length > 0 ? { key: keys[0][0], dir: keys[0][1] } : this._preferredSort;
    }

    /**
     * Saves, coalescing the bursts.
     *
     * Ticking four column checkboxes is one intent, not four; the debounce turns it into one
     * request. Saved views bypass it (`immediate`) because the server assigns their ids and drops
     * what it refuses — the answer is adopted, so it must not arrive half a second later, after
     * the user has clicked something else.
     */
    _persistPreferences({ immediate = false } = {}) {
        if (!this._hasPreferences) return;

        if (this._prefsSaveTimer) {
            clearTimeout(this._prefsSaveTimer);
            this._prefsSaveTimer = null;
        }

        if (immediate) {
            this._savePreferences();

            return;
        }

        this._prefsSaveTimer = setTimeout(() => {
            this._prefsSaveTimer = null;
            this._savePreferences();
        }, 400);
    }

    /** @returns {Promise<boolean>} whether the state reached the server */
    async _savePreferences(sortOverride = null) {
        try {
            const response = await fetch(this.preferencesUrlValue, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': this.preferencesCsrfValue,
                },
                body: JSON.stringify(this._preferencePayload(sortOverride)),
            });
            if (!response.ok) throw new Error(String(response.status));

            // The response is the truth: an over-long name was cut, a duplicate id was suffixed,
            // a twentieth view was refused. Adopting it here is what keeps the panel honest.
            const saved = await response.json();
            this._views = Array.isArray(saved.views) ? saved.views : [];
            this._activeViewId = this._matchingViewId();
            this._syncViewButtonLabel();
            if (this._openPanel === 'views') this._renderViewPanel();

            return true;
        } catch (e) {
            document.dispatchEvent(new CustomEvent('ui:toast', {
                detail: { message: this.t('datatable.error.saving'), type: 'error' },
            }));

            return false;
        }
    }

    /**
     * Back to the declared columns — visibility AND order — leaving the saved views alone. That is
     * what the button says, and it is deliberately not the DELETE route: "reset the columns" must
     * not also throw away the views someone spent time naming.
     */
    async _resetColumns() {
        // Snapshotted before the order changes, for the reason spelled out in `_moveColumn`.
        const sort = this._currentSort();

        this._columnPrefs = this._defaultColumnPrefs();
        await this._commitColumnOrder(sort);
    }

    _toggleColumn(key) {
        const prefs = this._columnPrefs || [];
        const target = prefs.find(pref => pref.key === key);
        // Hiding the last visible column reads as a broken table, so it is refused here as well as
        // by `_adoptPreferences` on the way back in.
        if (!target || (target.visible && prefs.filter(pref => pref.visible).length === 1)) return;

        target.visible = !target.visible;

        const index = this._columns.findIndex(col => col.data === key);
        if (this.dataTable && index >= 0) {
            // `visible()` redraws the header and body in place — no reload, no lost scroll. The
            // filter row is rebuilt rather than synced: DataTables removes the `<th>` of an
            // invisible column, so a row built for every column would sit one cell out of line.
            this.dataTable.column(index + this._bulkOffset).visible(target.visible, false);
            this._rebuildFilterRow();
            this.dataTable.columns.adjust();
            // Showing a column re-inserts the `<td>` of the LAST draw, not fresh ones. For a
            // column rendered as an IRI those cells hold the pending placeholder, because
            // `_resolvePageIris()` deliberately skips hidden columns — so the resolver was never
            // asked for those IRIs and nothing would ever ask again: no draw happens here. Without
            // this call the column stays on "…" until an unrelated sort, search or page change
            // triggers a draw.
            if (target.visible) {
                this._resolvePageIris(true);
            }
            this._renderCards();
        }

        this._persistPreferences();
        this._renderColumnPanel();
    }

    /** Moves `key` before or after `target` in the user's order. */
    async _moveColumn(key, target, before) {
        const prefs = this._columnPrefs || [];
        const from = prefs.findIndex(pref => pref.key === key);
        const onto = prefs.findIndex(pref => pref.key === target);
        if (from < 0 || onto < 0 || from === onto) return;

        // Snapshot the sort BEFORE reordering. `_currentSort()` resolves the live DataTables order
        // INDEX through the column list, and the splice below changes what that index points at:
        // without this line, dragging a column onto position 2 persists "sorted by whatever landed
        // in position 2" — the table comes back sorted by a column the user never clicked.
        const sort = this._currentSort();

        const [moved] = prefs.splice(from, 1);
        const at = prefs.findIndex(pref => pref.key === target);
        prefs.splice(before ? at : at + 1, 0, moved);

        await this._commitColumnOrder(sort);
    }

    /**
     * Saves a new column ORDER, then reloads the page.
     *
     * Visibility toggles in place — `column().visible()` is an API — but order has none: ColReorder
     * is a separate DataTables plugin nobody here owns. The obvious alternative, destroying the
     * table and building it again, does not work on a Stimulus-controlled element: `destroy()`
     * re-inserts the original `<table>` node, Stimulus sees a removal followed by an insertion and
     * disconnects then reconnects the controller. The reconnected instance short-circuits on
     * `data-datatable-initialized` while the instance that ordered the rebuild still holds a table
     * nobody owns — two tables, two filter rows, and a panel rendered from preferences the fresh
     * instance had reloaded from the server before the save landed.
     *
     * So: await the save, remember which panel was open, reload. The search, the filters and the
     * page all come back from `sessionStorage`, so the only thing the user pays is one navigation
     * for a gesture they make rarely.
     */
    async _commitColumnOrder(sort) {
        // A debounced save from an earlier tick would race the reload for no gain: the state it
        // carries is the state about to be sent.
        if (this._prefsSaveTimer) {
            clearTimeout(this._prefsSaveTimer);
            this._prefsSaveTimer = null;
        }

        this._rememberOpenPanel();

        // Reload only on a save that landed. Reloading after a failure would show the OLD order
        // next to a toast saying the save failed — two contradictory signals for one action.
        if (await this._savePreferences(sort)) window.location.reload();
    }

    /**
     * Which panel to reopen after the reload, keyed on the preferences URL so two tables on one
     * page never reopen each other's. Consumed once and cleared: a later reload for another reason
     * must not pop a panel open.
     */
    get _panelMemoryKey() {
        return `dt_panel_${this.preferencesUrlValue}`;
    }

    _rememberOpenPanel() {
        try {
            if (this._openPanel) sessionStorage.setItem(this._panelMemoryKey, this._openPanel);
        } catch (e) {
            // sessionStorage may be full or disabled — the panel simply does not reopen.
        }
    }

    _consumeRememberedPanel() {
        try {
            const remembered = sessionStorage.getItem(this._panelMemoryKey);
            sessionStorage.removeItem(this._panelMemoryKey);

            return remembered;
        } catch (e) {
            return null;
        }
    }

    // ── Saved views ────────────────────────────────────────────

    /** Mirrors the id the server derives from the name, so the optimistic row already has its key. */
    _slugify(value) {
        return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    }

    _saveCurrentView(name) {
        const trimmed = String(name || '').trim();
        if (trimmed === '') return;

        const sort = this._currentSort();
        this._views = [
            ...(this._views || []),
            {
                id: this._slugify(trimmed) || 'view',
                name: trimmed,
                filters: { ...this._activeFilters },
                sort: sort ? { key: sort.key, dir: sort.dir } : null,
                default: false,
            },
        ];

        this._persistPreferences({ immediate: true });
        this._renderViewPanel();
    }

    /** At most one default view — it is the one applied when the table opens in a new session. */
    _toggleDefaultView(id) {
        const wasDefault = (this._views || []).some(view => view.id === id && view.default);
        this._views = (this._views || []).map(view => ({ ...view, default: !wasDefault && view.id === id }));

        this._persistPreferences({ immediate: true });
        this._renderViewPanel();
    }

    _deleteView(id) {
        if (this._activeViewId === id) this._activeViewId = null;
        this._views = (this._views || []).filter(view => view.id !== id);

        this._persistPreferences({ immediate: true });
        this._renderViewPanel();
    }

    /**
     * Applies a view: its filters and its sort, page back to 1 — and never a page size, which is a
     * preference of its own and always wins.
     *
     * A view is a SEED, not a lock: the next explicit sort or filter change detaches it, so the
     * user is never fighting a state that comes back. That is `_activeViewId` being cleared in
     * `onDraw` as soon as the query stops matching.
     */
    _applyView(view) {
        this._activeFilters = { ...(view.filters || {}) };
        this._activeViewId = view.id;
        this._rebuildFilterRow();

        if (view.sort && view.sort.key) {
            const order = this._orderFromKeys([[view.sort.key, view.sort.dir]]);
            if (order.length > 0) this.dataTable.order(order);
        }

        this._closePanels();
        this.dataTable.page(0).draw(false);
    }

    /**
     * The view whose filters are exactly what is on screen, or null.
     *
     * Compared rather than remembered: a filter changed by hand, a sort clicked, a Mercure reload
     * all go through the same place, and comparing the query is the only thing that cannot forget
     * one of them.
     */
    _matchingViewId() {
        // No filter applied means no view is active — otherwise a view someone saved with no
        // filter at all would show as active on every unfiltered draw.
        if (Object.keys(this._activeFilters || {}).length === 0) return null;

        const current = JSON.stringify(this._sortedFilters(this._activeFilters));

        return (this._views || []).find(view => JSON.stringify(this._sortedFilters(view.filters)) === current)?.id ?? null;
    }

    _sortedFilters(filters) {
        return Object.keys(filters || {}).sort().map(key => [key, filters[key]]);
    }

    // ── Preference panels (toolbar) ────────────────────────────

    /**
     * The two dropdowns, injected into the top layout row next to the search.
     *
     * Built here rather than through DataTables' `layout` API for the same reason the mobile filter
     * button is: `layout` wants its cells declared before init, and these depend on preferences
     * that arrive with the table. The row is a flex container, so appending is enough.
     */
    _buildPreferenceControls() {
        if (!this._hasPreferences) return;

        const container = this.element.closest('.dt-container');
        const topRow = container?.querySelector('.dt-layout-row');
        if (!topRow || topRow.querySelector('.dt-prefs')) return;

        const group = document.createElement('div');
        group.className = 'dt-prefs-group';

        // A picker over a single column is a button that can do nothing.
        if (this._columns.length > 1) {
            group.appendChild(this._buildPanel('columns', 'fa-table-columns', 'datatable.columns.button'));
        }

        // A view stores FILTERS. Read on the declared filters, not the visible ones: hiding a
        // column must not make the saved views disappear with it.
        if ((this.filtersValue || []).length > 0) {
            group.appendChild(this._buildPanel('views', 'fa-bookmark', 'datatable.views.button'));
        }

        if (!group.firstChild) return;

        // Inside the SEARCH's own layout cell, immediately before it — not appended to the row.
        // The three controls are one cluster and have to stay aligned on the right together; the
        // cell's `flex-wrap` (stylesheet) is what drops the buttons onto their own line on a narrow
        // viewport instead of squeezing the search box. With the global search turned off there is
        // no end cell, and the row itself is the right place.
        const search = container?.querySelector('.dt-search');
        if (search?.parentElement) {
            search.parentElement.insertBefore(group, search);
        } else {
            topRow.appendChild(group);
        }

        this._installPanelDismiss();
        this._syncViewButtonLabel();

        // A reorder reloads the page, and the panel went with it. Reopening it is what makes
        // dragging two columns in a row feel like one gesture rather than two round trips.
        const reopen = this._consumeRememberedPanel();
        if (reopen) this._togglePanel(reopen);
    }

    _buildPanel(kind, icon, labelKey) {
        const wrapper = document.createElement('div');
        wrapper.className = `dt-prefs dt-prefs--${kind}`;
        wrapper.dataset.prefsKind = kind;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'dt-prefs-btn';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-haspopup', 'dialog');
        button.innerHTML = `<i class="fa-solid ${icon}"></i><span class="dt-prefs-btn-label">${this._escHtml(this.t(labelKey))}</span>`;
        button.addEventListener('click', () => this._togglePanel(kind));

        const panel = document.createElement('div');
        panel.className = 'dt-prefs-panel';
        panel.setAttribute('role', 'dialog');
        panel.hidden = true;

        wrapper.appendChild(button);
        wrapper.appendChild(panel);

        return wrapper;
    }

    _panelElement(kind) {
        return this.element.closest('.dt-container')?.querySelector(`.dt-prefs--${kind} .dt-prefs-panel`) ?? null;
    }

    _togglePanel(kind) {
        const opening = this._openPanel !== kind;
        this._closePanels();

        if (!opening) return;

        this._openPanel = kind;
        const panel = this._panelElement(kind);
        if (!panel) return;

        panel.hidden = false;
        panel.parentElement?.querySelector('.dt-prefs-btn')?.setAttribute('aria-expanded', 'true');

        if (kind === 'columns') this._renderColumnPanel();
        else this._renderViewPanel();

        // After rendering: the panel has to be laid out before it can be measured.
        this._anchorPanel(panel);
    }

    /**
     * Right-anchored by default, left-anchored when that would push the panel out of the table.
     *
     * A dropdown hanging off the RIGHT edge of its button extends leftwards — which is correct while
     * the button sits on the right of the toolbar, and wrong the moment the toolbar wraps and the
     * buttons end up flush left: a 16rem panel then reaches past the left of the screen.
     *
     * Decided by MEASUREMENT and not by a media query: `.dt-layout-row` wraps on content width, so
     * there is no breakpoint to key this on — the same viewport wraps or does not depending on how
     * long the "rows per page" label is in the current locale.
     */
    _anchorPanel(panel) {
        panel.classList.remove('dt-prefs-panel--start');

        const bounds = this.element.closest('.dt-container')?.getBoundingClientRect();
        if (!bounds) return;

        if (panel.getBoundingClientRect().left < bounds.left) {
            panel.classList.add('dt-prefs-panel--start');
        }
    }

    _closePanels() {
        this._openPanel = null;
        this.element.closest('.dt-container')?.querySelectorAll('.dt-prefs').forEach(wrapper => {
            const panel = wrapper.querySelector('.dt-prefs-panel');
            if (panel) panel.hidden = true;
            wrapper.querySelector('.dt-prefs-btn')?.setAttribute('aria-expanded', 'false');
        });
    }

    /**
     * One `document` listener for both panels, installed once and removed on disconnect. The
     * panels are rebuilt on every table rebuild; a listener added alongside them would accumulate
     * silently — the very defect the date-range filter had to be fixed for.
     */
    _installPanelDismiss() {
        if (this._panelDismiss) return;

        this._panelDismiss = (event) => {
            if (!this._openPanel) return;

            // A click on a control whose own handler re-rendered the panel — saving a view,
            // starring one, deleting one — reaches this listener with its target ALREADY DETACHED,
            // because `innerHTML` was replaced while the event was still bubbling. `closest()` then
            // answers null on a node with no document, and the panel closed on its own action.
            // `isConnected` is the only thing that tells that apart from a click somewhere else.
            const target = event.target;
            if (!(target instanceof Element) || !target.isConnected) return;

            if (!target.closest('.dt-prefs')) this._closePanels();
        };
        this._panelEscape = (event) => {
            if (event.key === 'Escape' && this._openPanel) this._closePanels();
        };

        document.addEventListener('click', this._panelDismiss);
        document.addEventListener('keydown', this._panelEscape);
    }

    _removePanelDismiss() {
        if (!this._panelDismiss) return;

        document.removeEventListener('click', this._panelDismiss);
        document.removeEventListener('keydown', this._panelEscape);
        this._panelDismiss = null;
        this._panelEscape = null;
    }

    /**
     * The views button wears the name of the view currently on screen, and falls back to its own
     * label when none is. It is the only place the active view is visible with the panel CLOSED —
     * without it, a table opened on its default view looks like a table with a filter someone
     * forgot to clear.
     */
    _syncViewButtonLabel() {
        const label = this.element.closest('.dt-container')?.querySelector('.dt-prefs--views .dt-prefs-btn-label');
        if (!label) return;

        const active = (this._views || []).find(view => view.id === this._activeViewId);
        label.textContent = active ? active.name : this.t('datatable.views.button');
    }

    _panelHeader(hintKey) {
        return `
            <div class="dt-prefs-head">
                <span class="dt-prefs-hint">${this._escHtml(this.t(hintKey))}</span>
                <button type="button" class="dt-prefs-close" aria-label="${this._escAttr(this.t('datatable.columns.close'))}">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>`;
    }

    _renderColumnPanel() {
        const panel = this._panelElement('columns');
        if (!panel) return;

        const rows = this._columns.map(col => {
            const visible = this._isColumnVisible(col.data);
            const only = visible && this._visibleColumns.length === 1;

            return `
                <div class="dt-prefs-row" draggable="true" data-column="${this._escAttr(col.data)}">
                    <i class="fa-solid fa-grip-vertical dt-prefs-handle" aria-hidden="true"></i>
                    <label class="dt-prefs-label">
                        <input type="checkbox" class="dt-prefs-check"${visible ? ' checked' : ''}${only ? ' disabled' : ''}>
                        <span>${this._escHtml(this._columnLabel(col))}</span>
                    </label>
                </div>`;
        }).join('');

        panel.innerHTML = `
            ${this._panelHeader('datatable.columns.hint')}
            <div class="dt-prefs-list">${rows}</div>
            <button type="button" class="dt-prefs-reset">
                <i class="fa-solid fa-rotate-left"></i>${this._escHtml(this.t('datatable.columns.reset'))}
            </button>`;

        panel.querySelector('.dt-prefs-close').addEventListener('click', () => this._closePanels());
        panel.querySelector('.dt-prefs-reset').addEventListener('click', () => this._resetColumns());
        panel.querySelectorAll('.dt-prefs-row').forEach(row => {
            row.querySelector('.dt-prefs-check').addEventListener('change', () => this._toggleColumn(row.dataset.column));
            this._wireColumnDrag(row);
        });
    }

    /**
     * A column header can be HTML (the bulk checkbox column's is), and `title` may carry markup a
     * provider wrote. The panel wants a plain label, so the tags are stripped rather than escaped —
     * escaping them would show `<input …>` in a checkbox list.
     */
    _columnLabel(col) {
        const label = String(col.title ?? col.data ?? '').replace(/<[^>]*>/g, '').trim();

        return label === '' ? String(col.data ?? '') : label;
    }

    /**
     * Native drag and drop: the rows are moved in the DOM as the pointer passes them, so what the
     * user sees during the drag is the order that will be saved. The commit happens on `dragend`,
     * once, from the DOM order — reading the DOM rather than tracking indexes means an interrupted
     * drag simply changes nothing.
     */
    _wireColumnDrag(row) {
        row.addEventListener('dragstart', (event) => {
            this._dragKey = row.dataset.column;
            row.classList.add('dt-prefs-row--dragging');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox ignores a drag whose dataTransfer carries nothing.
            event.dataTransfer.setData('text/plain', row.dataset.column);
        });

        row.addEventListener('dragend', () => {
            row.classList.remove('dt-prefs-row--dragging');
            const key = this._dragKey;
            this._dragKey = null;
            if (!key) return;

            const order = Array.from(row.parentElement.children).map(child => child.dataset.column);
            const target = order[order.indexOf(key) + 1];
            // Dropped last: anchor on the previous row instead, after it.
            if (target) this._moveColumn(key, target, true);
            else if (order.length > 1) this._moveColumn(key, order[order.length - 2], false);
        });

        row.addEventListener('dragover', (event) => {
            event.preventDefault();
            const dragged = row.parentElement?.querySelector('.dt-prefs-row--dragging');
            if (!dragged || dragged === row) return;

            const box = row.getBoundingClientRect();
            const before = event.clientY < box.top + box.height / 2;
            row.parentElement.insertBefore(dragged, before ? row : row.nextSibling);
        });
    }

    _renderViewPanel() {
        const panel = this._panelElement('views');
        if (!panel) return;

        const views = this._views || [];
        const rows = views.length === 0
            ? `<p class="dt-views-empty">${this._escHtml(this.t('datatable.views.empty'))}</p>`
            : views.map(view => `
                <div class="dt-views-row${view.id === this._activeViewId ? ' dt-views-row--active' : ''}" data-view="${this._escAttr(view.id)}">
                    <button type="button" class="dt-views-apply">${this._escHtml(view.name)}</button>
                    <button type="button" class="dt-views-icon dt-views-default${view.default ? ' dt-views-icon--on' : ''}"
                            aria-label="${this._escAttr(this.t('datatable.views.default'))}"
                            title="${this._escAttr(this.t('datatable.views.default'))}">
                        <i class="fa-${view.default ? 'solid' : 'regular'} fa-star"></i>
                    </button>
                    <button type="button" class="dt-views-icon dt-views-icon--danger dt-views-delete"
                            aria-label="${this._escAttr(this.t('datatable.views.delete'))}"
                            title="${this._escAttr(this.t('datatable.views.delete'))}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>`).join('');

        panel.innerHTML = `
            ${this._panelHeader('datatable.views.hint')}
            <div class="dt-prefs-list">${rows}</div>
            <div class="dt-views-save">
                <input type="text" class="dt-views-input" maxlength="60" placeholder="${this._escAttr(this.t('datatable.views.name'))}">
                <button type="button" class="dt-views-submit" disabled>${this._escHtml(this.t('datatable.views.save'))}</button>
            </div>`;

        panel.querySelector('.dt-prefs-close').addEventListener('click', () => this._closePanels());

        const input = panel.querySelector('.dt-views-input');
        const submit = panel.querySelector('.dt-views-submit');
        const sync = () => { submit.disabled = input.value.trim() === ''; };
        const save = () => {
            if (submit.disabled) return;
            this._saveCurrentView(input.value);
        };
        input.addEventListener('input', sync);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); save(); }
        });
        submit.addEventListener('click', save);

        panel.querySelectorAll('.dt-views-row').forEach(row => {
            const view = views.find(candidate => candidate.id === row.dataset.view);
            row.querySelector('.dt-views-apply').addEventListener('click', () => this._applyView(view));
            row.querySelector('.dt-views-default').addEventListener('click', () => this._toggleDefaultView(view.id));
            row.querySelector('.dt-views-delete').addEventListener('click', () => this._deleteView(view.id));
        });
    }

    // ── Column order ↔ DataTables indexes ──────────────────────

    /** The bulk checkbox column shifts every DataTables index by one. */
    get _bulkOffset() {
        return this._bulkActions.length > 0 ? 1 : 0;
    }

    /**
     * `defaultOrderValue` is written by a template as an INDEX into the declared columns — which
     * stops meaning anything the moment a user reorders them. Resolving it to a column key once,
     * against the declared list, is what keeps `[[2, "asc"]]` pointing at the same column after a
     * drag.
     */
    _defaultOrderKeys() {
        return (this.defaultOrderValue || [])
            .map(([index, dir]) => [this.columnsValue[Number(index)]?.data, dir === 'desc' ? 'desc' : 'asc'])
            .filter(([key]) => Boolean(key));
    }

    /** Column keys → DataTables order, dropping what this table no longer shows. */
    _orderFromKeys(keys) {
        const columns = this._columns;

        return (keys || [])
            .map(([key, dir]) => [columns.findIndex(col => col.data === key), dir === 'desc' ? 'desc' : 'asc'])
            .filter(([index]) => index >= 0)
            .map(([index, dir]) => [index + this._bulkOffset, dir]);
    }

    /** The reverse, so what is persisted survives a reorder or a hidden column. */
    _currentOrderKeys() {
        if (!this.dataTable) return [];

        const columns = this._columns;

        return this.dataTable.order()
            .map(([index, dir]) => [columns[Number(index) - this._bulkOffset]?.data, dir])
            .filter(([key]) => Boolean(key));
    }

    /**
     * The filters the table opens with.
     *
     * A STARRED view wins over the filters this session had stored. That order is the whole meaning
     * of the star: "vue par défaut à l'ouverture" is an explicit, durable instruction about how
     * this table opens, while the session's sticky filters are an implicit convenience nobody asked
     * for by name. With the previous order, saving a second view left the session pinned to it and
     * the starred one never came back — which reads as "the view I just saved became the default"
     * (reported 2026-08-24).
     *
     * The cost is stated rather than hidden: with a view starred, an ad-hoc filter does not survive
     * a navigation. That is precisely what starring one asks for, and un-starring it gives the
     * sticky behaviour back.
     */
    _openingFilters(saved) {
        const view = (this._views || []).find(candidate => candidate.default);
        if (view) {
            this._activeViewId = view.id;

            return { ...(view.filters || {}) };
        }

        return { ...(saved?.filters ?? {}) };
    }

    /**
     * Sort precedence, highest first:
     *
     * 1. **the starred view's sort** — it opens the table, so it brings its ordering. Above the
     *    session for the same reason its filters are: see `_openingFilters()`. The two have to
     *    agree, or the table opens on one view's filters sorted by another's column;
     * 2. **this session's last click** — restored from `sessionStorage`;
     * 3. **the saved sort preference** — the last ordering this user chose, months ago;
     * 4. **the template's `default-order`** — the provider's intent;
     * 5. the first column ascending, so a table always has an order.
     *
     * Each candidate is resolved through `_orderFromKeys`, which drops a column this table no
     * longer has — so a stale preference falls through to the next candidate instead of leaving
     * the table unsorted.
     */
    _resolveOrder(saved) {
        const view = (this._views || []).find(candidate => candidate.default);
        const candidates = [
            view?.sort?.key ? [[view.sort.key, view.sort.dir]] : null,
            saved?.orderKeys,
            this._preferredSort?.key ? [[this._preferredSort.key, this._preferredSort.dir]] : null,
            this._defaultOrderKeys(),
        ];

        for (const keys of candidates) {
            const order = this._orderFromKeys(keys);
            if (order.length > 0) return order;
        }

        return [[this._bulkOffset, 'asc']];
    }

    // ── Filter row rebuild ─────────────────────────────────────

    /**
     * Rebuilds the filter row from scratch.
     *
     * Two reasons it cannot be patched in place. DataTables removes the `<th>` of an invisible
     * column, so the row must be rebuilt against the columns that are actually there. And each
     * widget reads `_activeFilters` when it is created — the date-range field renders its "from →
     * to" label in a closure at build time — so applying a saved view by writing into the inputs
     * would leave a field showing the previous range.
     */
    _rebuildFilterRow() {
        this._destroyFilters();
        this.element.querySelector('.dt-filter-row')?.remove();
        this._buildFilters();
        this._syncFilterSelections();
        this._updateMobileFilterBadge();
    }

    initializeDataTable() {
        const panel = this.element.closest('.panel');
        this._blockId = this.block(panel || this.element);

        const saved = this._loadState();

        // The filters are settled BEFORE the table exists, not restored after it.
        //
        // Every filter widget reads `_activeFilters` as it is created — the date-range field
        // renders its "from → to" label in a closure at build time — and so does the first AJAX
        // call. Setting it here means ONE request carrying the right query, instead of an
        // unfiltered draw immediately corrected by a filtered one. It is also what lets a default
        // view apply on the first paint rather than as a visible jump.
        this._activeFilters = this._openingFilters(saved);
        const columns = this.buildColumns();

        const config = {
            processing: false,
            serverSide: true,
            ajax: {
                url: this.apiUrlValue,
                type: 'GET',
                headers: {
                    'Authorization': `Bearer ${window.jwtToken}`,
                    'Content-Type': 'application/ld+json',
                    'Accept': 'application/ld+json',
                    ...(window.organizationSlug ? { 'X-ORGANIZATION': window.organizationSlug } : {}),
                },
                data: (d) => this.transformRequestParams(d),
                dataSrc: (json) => this.transformResponse(json),
                error: (xhr, error, code) => this.handleError(xhr, error, code),
                complete: () => {
                    if (this._blockId) {
                        this.unblock(this._blockId);
                        this._blockId = null;
                    }
                }
            },
            columns: columns,
            pageLength: saved?.pageLength || this.pageLengthValue,
            order: this._resolveOrder(saved),
            displayStart: saved?.start || 0,
            search: { search: saved?.search || '' },
            language: this.getLanguageConfig(),
            layout: {
                topStart: 'pageLength',
                topEnd: this.searchEnabledValue ? 'search' : null,
                bottomStart: 'info',
                bottomEnd: 'paging'
            },
            responsive: {
                details: {
                    type: 'inline'
                }
            },
            initComplete: () => {
                this._buildFilters();
                this._buildPreferenceControls();
                this._buildMobileFilterButton();
                this._restoreState(saved);
                this._updateMobileFilterBadge();
            },
            drawCallback: () => this.onDraw(),
            preDrawCallback: () => this.onPreDraw(),
            createdRow: (row, data) => {
                // Soft-deleted rows (deletedAt !== null) get a visual marker
                // so super-admins spot them at a glance. Regular users never
                // see these rows because the Doctrine `soft_delete` filter
                // is still active for them.
                if (data && data.deletedAt) {
                    row.classList.add('datatable-row-deleted');
                }
            },
            autoWidth: false,
            retrieve: true,
            destroy: true,
            deferRender: true,
            stateSave: false
        };

        this.dataTable = new window.DataTable(this.element, config);
        this._injectSearchIcon();
        // P2 (2026-05-14-4) — DataTables 2.x wraps the <table> in a
        // `<div class="dt-layout-cell dt-layout-full">`. The `dt-layout-cell`
        // class makes it a flex item inside `.dt-layout-row` which centers
        // oversized children (the table itself) → the first column gets
        // pushed left of the visible area. We strip the cell class on the
        // table's direct parent so the wrapper becomes a normal block,
        // and our `.panel .dt-layout-full { overflow-x: auto }` CSS takes
        // over with a proper horizontal scrollbar.
        const tableWrapper = this.element.parentElement;
        if (tableWrapper && tableWrapper.classList.contains('dt-layout-cell')) {
            tableWrapper.classList.remove('dt-layout-cell');
        }

        // Filter row built in `initComplete` is unknown to DataTables'
        // `aoHeader` — Responsive 3.x hides columns by writing inline
        // `display: none` on the cells it knows about (the original
        // thead row + tbody cells of that column), but it never touches
        // our `.dt-filter-row` because we append it post-init. The
        // *robust* fix is to mirror the FIRST thead row's per-cell
        // `display` state onto the filter row whenever DataTables
        // redraws or Responsive recalculates. This avoids reading
        // plugin-internal state (`_responsive.s.current`,
        // `column.visible()`, etc.) — the actual DOM is the truth.
        this.dataTable.on('responsive-resize.dt draw.dt column-visibility.dt', () => {
            this._syncFilterRowVisibility();
        });
        // Some viewports trigger Responsive recalc on plain window resize
        // without redrawing (between breakpoints + animation frames).
        // A debounced window listener catches those edge cases.
        this._onWindowResize = () => {
            if (this._resizeRaf) cancelAnimationFrame(this._resizeRaf);
            this._resizeRaf = requestAnimationFrame(() => {
                this._syncFilterRowVisibility();
                // A resize with a panel open changes which side it may hang off — the toolbar may
                // have just wrapped underneath it.
                const open = this._openPanel ? this._panelElement(this._openPanel) : null;
                if (open) this._anchorPanel(open);
            });
        };
        window.addEventListener('resize', this._onWindowResize);
    }

    onPreDraw() {
        if (!this._blockId) {
            const panel = this.element.closest('.panel');
            this._blockId = this.block(panel || this.element);
        }
    }

    onDraw() {
        if (this._blockId) {
            this.unblock(this._blockId);
            this._blockId = null;
        }
        this._saveState();
        // Recomputed on every draw rather than remembered: a filter cleared by hand, a Select2
        // change, a Mercure reload all land here, and comparing the query is the only check that
        // cannot forget one of them. The panel then shows no view as active, which is exactly
        // what "you have changed the view" should look like.
        this._activeViewId = this._matchingViewId();
        this._syncViewButtonLabel();
        this._resolvePageIris();
        this._renderCards();
        // Reset bulk selection whenever the query (search/filters/sort)
        // changes — we keep selection across pagination/Mercure reloads
        // so quick page jumps don't lose state.
        this._resetBulkSelectionIfQueryChanged();
        this._wireBulkCheckboxes();
        this._renderBulkBar();
    }

    _resetBulkSelectionIfQueryChanged() {
        if (this._bulkActions.length === 0 || !this.dataTable) {
            return;
        }
        const signature = JSON.stringify({
            search: this.dataTable.search(),
            order: this.dataTable.order(),
            filters: this._activeFilters,
        });
        if (this._lastQuerySignature === undefined) {
            this._lastQuerySignature = signature;
            return;
        }
        if (this._lastQuerySignature !== signature) {
            this._lastQuerySignature = signature;
            this._bulkSelection.clear();
        }
    }

    _injectSearchIcon() {
        const searchInput = this.element.closest('.dt-container')?.querySelector('.dt-search input');
        if (searchInput && !searchInput.dataset.iconInjected) {
            const wrapper = searchInput.parentElement;
            if (wrapper) {
                wrapper.style.position = 'relative';
                searchInput.style.paddingLeft = '2.25rem';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none';
                wrapper.insertBefore(icon, searchInput);
                searchInput.dataset.iconInjected = '1';
            }
        }
    }

    // ── IRI resolution (batch after draw) ───────────────────────

    /**
     * Resolves the IRIs of the current page, then redraws so the labels replace the pending
     * placeholders. Called on every draw, and by `_toggleColumn()` when a column is shown.
     *
     * @param {boolean} force redraw even when nothing had to be fetched. A column that has just
     *   been made visible carries the cells rendered while it was hidden — placeholders, since the
     *   collection below skips hidden columns — so they need re-rendering whether or not their IRIs
     *   were already cached.
     *
     * A call arriving while a resolution is in flight returns: that pass ends with a redraw, whose
     * `draw` callback comes back here with the flag cleared and the new column visible.
     */
    async _resolvePageIris(force = false) {
        if (!this.dataTable || this._resolving) return;

        const data = this.dataTable.rows({ page: 'current' }).data().toArray();
        // The visible columns only: an IRI in a hidden column is never rendered, so resolving it
        // would be a request for a label nobody reads.
        const iriColumns = this._visibleColumns
            .map((col, idx) => ({ ...col, idx }))
            .filter(col => col.render === 'iri' || col.render === 'userIri');

        if (iriColumns.length === 0) return;

        const iris = new Set();
        for (const row of data) {
            for (const col of iriColumns) {
                const val = row[col.data];
                if (typeof val === 'string' && val.startsWith('/api/') && iriResolver.get(val) === undefined) {
                    iris.add(val);
                }
            }
        }

        if (iris.size === 0 && !force) return;

        if (iris.size > 0) {
            this._resolving = true;
            await iriResolver.resolveMany([...iris]);
            this._resolving = false;
        }

        this.dataTable.rows({ page: 'current' }).invalidate('data').draw(false);
    }

    // ── Mercure real-time updates ─────────────────────────────

    _subscribeMercure() {
        this._unsubscribeMercure();
        this._pendingEvents = [];
        this._unsubscribe = mercureBus.onMessage((payload) => this._onFeedEvent(payload));
    }

    _onFeedEvent(payload) {
        if (!payload || typeof payload !== 'object') return;

        // Feed payload: { type, iri, id, action, topic, at, actorId, etag, changedFields?, count? }
        if (!payload.action || !payload.type) return;

        if (!this._feedScopeMatches(payload)) return;
        if (!this._feedMatchesTable(payload)) return;
        if (payload.iri) iriResolver.invalidate(payload.iri);

        // `created`, `restored` and `bulk` can shift pagination or row
        // counts → always reload. `updated` and `deleted` only affect a
        // single row — skip the reload when that row is not on the current
        // page. Deleting a row that isn't rendered doesn't move any visible
        // row (the page slice above the deletion is untouched), so the
        // suppression is safe.
        if ((payload.action === 'updated' || payload.action === 'deleted')
            && !this._feedMatchesVisibleId(payload)) {
            return;
        }

        this._pendingEvents.push(payload);
        this._scheduleReloadOrBanner();
    }

    /**
     * Returns true when the event payload references a row currently
     * rendered on the DataTable's active page. Used to short-circuit
     * reloads triggered by `updated` events on invisible rows.
     */
    _feedMatchesVisibleId(payload) {
        if (!this.dataTable) return false;

        const id = this._resolvePayloadId(payload);
        if (id === null) return false;

        const rows = this.dataTable.rows({ page: 'current' }).data().toArray();
        return rows.some(row => {
            const rowId = row?.id ?? row?.['@id'];
            if (rowId === undefined || rowId === null) return false;
            const normalised = typeof rowId === 'string' && rowId.startsWith('/api/')
                ? rowId.split('/').pop()
                : rowId;
            return String(normalised) === id;
        });
    }

    _resolvePayloadId(payload) {
        if (payload.id !== undefined && payload.id !== null && payload.id !== '') {
            return String(payload.id);
        }
        if (typeof payload.iri === 'string' && payload.iri !== '') {
            const tail = payload.iri.split('/').pop();
            return tail ? String(tail) : null;
        }
        return null;
    }

    /**
     * Defense-in-depth against cross-tenant reload noise.
     *
     * An org-scoped datatable (`data-datatable-scope="organization"`) must
     * only react to `/organizations/{id}/feed` events — never to
     * `/admin/feed` (super-admin mutations of orphan rows) or `/global/feed`
     * (platform-wide announcements). The backend already routes these to
     * distinct topics via `EntityChangePublisher::resolveTopic()` and the
     * org JWT doesn't allow-list `/admin/feed`; this is the client-side
     * belt-and-braces filter, using the `topic` field echoed in the payload.
     *
     * When no scope is declared or the payload predates the `topic` field,
     * we fall back to the previous type-based matching (opt-in filter).
     */
    _feedScopeMatches(payload) {
        const scope = this.element.dataset.datatableScope;
        if (scope !== 'organization') return true;
        if (typeof payload.topic !== 'string' || payload.topic === '') return true;
        return payload.topic.startsWith('/organizations/');
    }

    _feedMatchesTable(payload) {
        // Org-scoped tables pass a filter via query string (e.g.
        // `/api/users?organization=/api/organizations/5`). Strip it before
        // matching — otherwise `/api/users/12`.startsWith(
        // `/api/users?organization=.../`) is false and every event is
        // dropped silently.
        const baseApiUrl = this.apiUrlValue.split('?')[0];
        if (payload.iri && typeof payload.iri === 'string') {
            return payload.iri.startsWith(baseApiUrl + '/') || payload.iri === baseApiUrl;
        }
        // Fallback: no IRI (non-ApiPlatform entity) — match by type against table resource slug
        const slug = baseApiUrl.split('/').pop() || '';
        return slug.toLowerCase().startsWith(String(payload.type).toLowerCase());
    }

    _scheduleReloadOrBanner() {
        const BURST_THRESHOLD = 4;
        const DEBOUNCE_MS = 500;

        clearTimeout(this._mercureReloadTimer);
        this._mercureReloadTimer = setTimeout(() => {
            const count = this._pendingEvents.length;
            this._pendingEvents = [];

            if (count >= BURST_THRESHOLD) {
                this._showCoalescenceBanner(count);
                return;
            }

            this._hideCoalescenceBanner();
            this.dataTable?.ajax.reload(null, false);
        }, DEBOUNCE_MS);
    }

    _showCoalescenceBanner(count) {
        let banner = this._coalescenceBanner;
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'flex items-center justify-between gap-3 px-3 py-2 mb-2 rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 text-sm text-amber-800 dark:text-amber-200';
            banner.innerHTML = `
                <span data-datatable-coalescence-message></span>
                <button type="button" class="btn-secondary text-xs px-2 py-1" data-datatable-coalescence-refresh>
                    <i class="fa-solid fa-arrows-rotate mr-1"></i>${this._escapeHtml(this.t('datatable.coalescence.refresh'))}
                </button>
            `;
            banner.querySelector('[data-datatable-coalescence-refresh]').addEventListener('click', () => {
                this._hideCoalescenceBanner();
                this.dataTable?.ajax.reload(null, false);
            });

            const container = this.element.closest('.dt-container') || this.element.parentNode;
            container.insertBefore(banner, container.firstChild);
            this._coalescenceBanner = banner;
        }

        const tpl = this.t('datatable.coalescence.message');
        banner.querySelector('[data-datatable-coalescence-message]').textContent =
            tpl.replace('%count%', String(count));
    }

    _hideCoalescenceBanner() {
        if (this._coalescenceBanner) {
            this._coalescenceBanner.remove();
            this._coalescenceBanner = null;
        }
    }

    _escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    _unsubscribeMercure() {
        if (this._unsubscribe) {
            this._unsubscribe();
            this._unsubscribe = null;
        }
        this._hideCoalescenceBanner();
    }

    // ── State persistence (survives redirects after actions) ────

    get _stateKey() {
        return `dt_state_${this.apiUrlValue}`;
    }

    _saveState() {
        if (!this.dataTable) return;

        const info = this.dataTable.page.info();
        const order = this.dataTable.order();
        const searchInput = this.element.closest('.dt-container')?.querySelector('.dt-search input');

        const state = {
            start: info.start,
            pageLength: info.length,
            // Column KEYS, not DataTables indexes: an index means nothing once the user has
            // reordered or hidden a column, and a stale one silently sorts by the wrong field.
            orderKeys: this._currentOrderKeys(),
            search: searchInput?.value || '',
            filters: { ...this._activeFilters },
        };

        try {
            sessionStorage.setItem(this._stateKey, JSON.stringify(state));
        } catch (e) {
            // sessionStorage might be full or disabled
        }
    }

    _loadState() {
        try {
            const raw = sessionStorage.getItem(this._stateKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * What is left to restore once the table is up.
     *
     * The filters themselves were applied before init (cf. `initializeDataTable`), so there is no
     * redraw here — the first request already carried them. What remains is the two things that
     * live in widgets rather than in the query: the search box's visible text, and the selected
     * option of each Select2, which has to be created before it can be selected.
     */
    _restoreState(saved) {
        if (!this.dataTable) return;

        if (saved?.search) {
            const searchInput = this.element.closest('.dt-container')?.querySelector('.dt-search input');
            if (searchInput) {
                searchInput.value = saved.search;
            }
        }

        this._syncFilterSelections();
    }

    /**
     * Pushes `_activeFilters` into the filter widgets.
     *
     * Select2 cannot select a value it has no option for, and an AJAX filter has none until the
     * user opens it — so the option is created here with the raw value as its label, then patched
     * with the readable one. Called after any rebuild of the filter row, which is also how a saved
     * view's filters become visible in the header.
     */
    _syncFilterSelections() {
        if (!window.jQuery) return;

        const $ = window.jQuery;
        this.element.querySelectorAll('.dt-filter-select').forEach(select => {
            const value = this._activeFilters[select.dataset.filterParam];
            if (value === undefined || value === '') return;

            if (select.dataset.filterType === 'api') {
                // P2 (report 2026-05-21) — option is created with the raw
                // value (IRI/id) as label, then resolved to a readable label.
                const opt = new Option(value, value, true, true);
                $(select).append(opt).trigger('change.select2');
                this._resolveFilterOptionLabel(select, value, opt);
            } else {
                $(select).val(value).trigger('change.select2');
            }
        });
    }

    // ── Filters ────────────────────────────────────────────────

    _buildMobileFilterButton() {
        if (!this.filtersValue || this.filtersValue.length === 0) return;

        const container = this.element.closest('.dt-container');
        if (!container) return;

        // Find the top layout row (where search lives)
        const topRow = container.querySelector('.dt-layout-row');
        if (!topRow || topRow.querySelector('.dt-mobile-filter-btn')) return;

        // Count active filters for badge
        const activeCount = Object.keys(this._activeFilters).length;
        const badgeHtml = activeCount > 0
            ? `<span class="dt-mobile-filter-badge">${activeCount}</span>`
            : '<span class="dt-mobile-filter-badge hidden">0</span>';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dt-mobile-filter-btn';
        btn.innerHTML = `<i class="fa-solid fa-sliders"></i> ${this.t('datatable.filters')} ${badgeHtml}`;
        btn.addEventListener('click', () => this._openMobileFilters());

        topRow.appendChild(btn);
    }

    _openMobileFilters() {
        // Remove existing overlay
        this._closeMobileFilters();

        const overlay = document.createElement('div');
        overlay.className = 'dt-mobile-filter-overlay';

        const sheet = document.createElement('div');
        sheet.className = 'dt-mobile-filter-sheet';

        // Header
        const header = document.createElement('div');
        header.className = 'dt-mobile-filter-header';
        header.innerHTML = `
            <span class="text-base font-semibold text-slate-900"><i class="fa-solid fa-sliders mr-2 text-slate-400"></i>${this.t('datatable.filters')}</span>
            <button type="button" class="dt-mobile-filter-close"><i class="fa-solid fa-xmark"></i></button>
        `;
        header.querySelector('.dt-mobile-filter-close').addEventListener('click', () => this._closeMobileFilters());
        sheet.appendChild(header);

        // Filter fields
        const body = document.createElement('div');
        body.className = 'dt-mobile-filter-body';

        this.filtersValue.forEach(config => {
            const field = document.createElement('div');
            field.className = 'dt-mobile-filter-field';

            const label = document.createElement('label');
            label.className = 'dt-mobile-filter-label';
            label.textContent = config.placeholder || config.param;
            field.appendChild(label);

            if (config.type === 'daterange') {
                const wrapper = document.createElement('div');
                wrapper.className = 'flex gap-2';

                const fromInput = document.createElement('input');
                fromInput.type = 'date';
                fromInput.className = 'dt-mobile-filter-input flex-1';
                fromInput.dataset.filterParam = config.param + '[after]';
                fromInput.dataset.dateGranularity = config.granularity || 'date';
                fromInput.value = this._civilDateFromIso(this._activeFilters[config.param + '[after]']);

                const toInput = document.createElement('input');
                toInput.type = 'date';
                toInput.className = 'dt-mobile-filter-input flex-1';
                toInput.dataset.filterParam = config.param + '[before]';
                toInput.dataset.dateGranularity = config.granularity || 'date';
                toInput.value = this._civilDateFromIso(this._activeFilters[config.param + '[before]']);

                wrapper.appendChild(fromInput);
                wrapper.appendChild(toInput);
                field.appendChild(wrapper);
            } else {
                const select = document.createElement('select');
                select.className = 'dt-mobile-filter-select';
                select.dataset.filterParam = config.param;
                select.dataset.filterType = config.type;

                const emptyOpt = document.createElement('option');
                emptyOpt.value = '';
                emptyOpt.textContent = '— ' + (config.placeholder || 'Tous') + ' —';
                select.appendChild(emptyOpt);

                if (config.type === 'static' && config.options) {
                    config.options.forEach(opt => {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        if (this._activeFilters[config.param] === opt.value) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });
                }

                if (config.type === 'api') {
                    select.dataset.filterUrl = config.url;
                    select.dataset.filterTextKey = config.textKey || 'name';
                    select.dataset.filterIdKey = config.idKey || 'id';
                    select.dataset.filterSearchKey = config.searchKey || config.textKey || 'name';
                    if (config.dependsOn) select.dataset.filterDependsOn = config.dependsOn;
                    if (config.dependsParam) select.dataset.filterDependsParam = config.dependsParam;

                    // If there's an active filter value, add it as selected option
                    const activeVal = this._activeFilters[config.param];
                    if (activeVal) {
                        const opt = document.createElement('option');
                        opt.value = activeVal;
                        opt.textContent = activeVal;
                        opt.selected = true;
                        select.appendChild(opt);
                        // P2 (report 2026-05-21) — resolve the IRI/id to its label.
                        this._resolveFilterOptionLabel(select, activeVal, opt);
                    }
                }

                field.appendChild(select);
            }

            body.appendChild(field);
        });

        sheet.appendChild(body);

        // Footer with action buttons
        const footer = document.createElement('div');
        footer.className = 'dt-mobile-filter-footer';
        footer.innerHTML = `
            <button type="button" class="dt-mobile-filter-reset">${this.t('datatable.filter_reset')}</button>
            <button type="button" class="dt-mobile-filter-apply">${this.t('datatable.filter_apply')}</button>
        `;

        footer.querySelector('.dt-mobile-filter-reset').addEventListener('click', () => {
            const $ = window.jQuery;
            body.querySelectorAll('select').forEach(s => {
                s.value = '';
                // Refresh the Select2 widget so the cleared value is reflected.
                if ($ && $(s).data('select2')) {
                    $(s).val(null).trigger('change.select2');
                }
            });
            body.querySelectorAll('input').forEach(i => { i.value = ''; });
        });

        footer.querySelector('.dt-mobile-filter-apply').addEventListener('click', () => {
            // Collect filter values from mobile fields. <input type="date">
            // for `[after]`/`[before]` => convert civil local date to UTC
            // ISO so the API DateFilter respects the browser timezone.
            // Cf. report 2026-05-23 § B.4.
            body.querySelectorAll('select, input').forEach(el => {
                const param = el.dataset.filterParam;
                if (!param) return;
                if (el.value) {
                    if (el.type === 'date' && (param.endsWith('[after]') || param.endsWith('[before]'))) {
                        const isAfter = param.endsWith('[after]');
                        this._activeFilters[param] = this._dateRangeFilterValue(el.value, isAfter, el.dataset.dateGranularity);
                    } else {
                        this._activeFilters[param] = el.value;
                    }
                } else {
                    delete this._activeFilters[param];
                }
            });

            // Sync desktop filters
            this._syncDesktopFilters();
            this.dataTable.ajax.reload();
            this._closeMobileFilters();
            this._updateMobileFilterBadge();
        });

        sheet.appendChild(footer);
        overlay.appendChild(sheet);

        // Close on overlay click
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this._closeMobileFilters();
        });

        document.body.appendChild(overlay);
        this._mobileFilterOverlay = overlay;

        // Init Select2 on API filters in mobile sheet
        requestAnimationFrame(() => this._initMobileSelect2(body));

        // Animate in
        requestAnimationFrame(() => {
            overlay.classList.add('active');
            sheet.classList.add('active');
        });
    }

    _initMobileSelect2(body) {
        if (!window.jQuery || !window.jQuery.fn.select2) return;
        const $ = window.jQuery;

        const sheet = body.closest('.dt-mobile-filter-sheet');

        // Static filters (e.g. deal status / stage) must be Select2 too, for a
        // consistent look with the API filters — not bare <select>.
        body.querySelectorAll('select[data-filter-type="static"]').forEach(select => {
            $(select).select2({
                placeholder: '— ' + (this.filtersValue.find(f => f.param === select.dataset.filterParam)?.placeholder || '') + ' —',
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 8,
                dropdownParent: sheet,
            });
        });

        body.querySelectorAll('select[data-filter-type="api"]').forEach(select => {
            $(select).select2({
                placeholder: '— ' + (this.filtersValue.find(f => f.param === select.dataset.filterParam)?.placeholder || 'Rechercher') + ' —',
                allowClear: true,
                width: '100%',
                minimumInputLength: 1,
                dropdownParent: body.closest('.dt-mobile-filter-sheet'),
                language: {
                    inputTooShort: () => this.t('datatable.filter.input_too_short'),
                    searching: () => this.t('datatable.filter.searching'),
                    noResults: () => this.t('datatable.filter.no_results'),
                },
                ajax: {
                    url: select.dataset.filterUrl,
                    dataType: 'json',
                    delay: 300,
                    headers: {
                        'Accept': 'application/ld+json',
                        'Authorization': window.jwtToken ? `Bearer ${window.jwtToken}` : undefined,
                        ...(window.organizationSlug ? { 'X-ORGANIZATION': window.organizationSlug } : {}),
                    },
                    data: (params) => {
                        const query = {};
                        const searchParam = select.dataset.filterSearchKey || select.dataset.filterTextKey || 'name';
                        query[searchParam] = params.term || '';
                        query.size = 20;
                        this._applyDependentScope(select, query);
                        return query;
                    },
                    processResults: (data) => {
                        const items = data.member || data['hydra:member'] || [];
                        return {
                            results: items.map(item => ({
                                id: item[select.dataset.filterIdKey || 'id'] ?? item.id,
                                text: item[select.dataset.filterTextKey || 'name'] ?? String(item.id),
                            }))
                        };
                    },
                    cache: true,
                },
            });
        });

        // Dependent mobile filters: when the parent changes, clear the child
        // selection so a value of the previous parent isn't applied. The sheet
        // applies on its button, so no reload is needed here.
        body.querySelectorAll('[data-filter-depends-on]').forEach(child => {
            const parent = body.querySelector(`[data-filter-param="${child.dataset.filterDependsOn}"]`);
            if (!parent) return;
            $(parent).on('change.dtdep', () => {
                if (child.value) $(child).val(null).trigger('change.select2');
            });
        });
    }

    _closeMobileFilters() {
        if (this._mobileFilterOverlay) {
            this._mobileFilterOverlay.remove();
            this._mobileFilterOverlay = null;
        }
    }

    _syncDesktopFilters() {
        if (!window.jQuery) return;
        const $ = window.jQuery;

        this.element.querySelectorAll('.dt-filter-select').forEach(select => {
            const param = select.dataset.filterParam;
            const value = this._activeFilters[param] || '';
            if ($(select).data('select2')) {
                $(select).val(value).trigger('change.select2');
            }
        });

        this.element.querySelectorAll('.dt-filter-date').forEach(input => {
            const param = input.dataset.filterParam;
            input.value = this._activeFilters[param] || '';
        });
    }

    _updateMobileFilterBadge() {
        const badge = this.element.closest('.dt-container')?.querySelector('.dt-mobile-filter-badge');
        if (!badge) return;
        const count = Object.keys(this._activeFilters).length;
        badge.textContent = count;
        badge.classList.toggle('hidden', count === 0);
    }

    _buildFilters() {
        if (!this.filtersValue || this.filtersValue.length === 0) return;
        if (this.element.querySelector('.dt-filter-row')) return;

        const thead = this.element.querySelector('thead');
        if (!thead) return;

        // A column the user hid has NO `<th>` in the header row — DataTables removes it rather
        // than hiding it — so a filter row built for every column would sit one cell out of line
        // from that point on. Only the columns actually rendered get a cell.
        const allColumns = [
            ...this._columns.filter(col => this._isColumnVisible(col.data)),
            ...(this.actionsValue && this.actionsValue.length > 0 ? [{ data: '__actions__' }] : []),
        ];

        // No row at all when not one VISIBLE column carries a filter.
        //
        // The table declares filters, so the guard above passes — but the user may have hidden
        // every column that has one. Building the row anyway draws a strip of empty `<th>` across
        // the width of the table: a second header row, as tall as a filter field, filtering
        // nothing. The filters of the hidden columns are still reachable from the mobile filter
        // sheet, which lists them all on purpose, and re-showing one of those columns rebuilds
        // this row.
        if (!allColumns.some(col => this.filtersValue.some(filter => filter.column === col.data))) {
            return;
        }

        const filterRow = document.createElement('tr');
        filterRow.className = 'dt-filter-row';

        // Match the exact column count DataTables renders: bulk checkbox
        // (if enabled) → data columns → actions column.
        if (this._bulkActions.length > 0) {
            filterRow.appendChild(document.createElement('th'));
        }

        allColumns.forEach(col => {
            const th = document.createElement('th');
            const filterConfig = this.filtersValue.find(f => f.column === col.data);

            if (filterConfig) {
                if (filterConfig.type === 'daterange') {
                    this._createDateRangeFilter(th, filterConfig);
                } else {
                    const select = this._createFilterSelect(filterConfig);
                    th.appendChild(select);
                }
            }

            filterRow.appendChild(th);
        });

        thead.appendChild(filterRow);
        this._initFilterSelect2();
        // Initial viewport may already have some columns hidden by the
        // Responsive plugin (e.g. < 1280 px) — align right after build.
        this._syncFilterRowVisibility();
    }

    /**
     * Mirror the first `<thead> <tr>`'s per-cell `display` state onto
     * the `.dt-filter-row` cells. Whatever mechanism DataTables /
     * Responsive use to hide a column (inline `display: none`,
     * `dtr-hidden` class, CSS rule…), the computed style on the main
     * header cell is the authoritative signal. Mirroring it sidesteps
     * the plugin's internal data structures and the gotcha that
     * Responsive does not call `column.visible()` for its own hides.
     */
    _syncFilterRowVisibility() {
        const filterRow = this.element.querySelector('.dt-filter-row');
        if (!filterRow) return;

        const thead = this.element.querySelector('thead');
        if (!thead) return;

        // The thead may contain ≥ 2 rows (header + filter). Find the
        // first non-filter row — that's the source of truth.
        const headerRow = Array.from(thead.rows).find(r => r !== filterRow);
        if (!headerRow) return;

        Array.from(headerRow.cells).forEach((headerCell, i) => {
            const filterCell = filterRow.cells[i];
            if (!filterCell) return;
            // `getComputedStyle` covers all hide mechanisms: inline
            // `display:none`, class-based, CSS rules. Cheap because the
            // browser already has the layout box.
            const hidden = window.getComputedStyle(headerCell).display === 'none';
            filterCell.style.display = hidden ? 'none' : '';
        });
    }

    _createFilterSelect(config) {
        const select = document.createElement('select');
        select.dataset.filterParam = config.param;
        select.dataset.filterType = config.type;
        select.className = 'dt-filter-select';

        if (config.type === 'api') {
            select.dataset.filterUrl = config.url;
            select.dataset.filterTextKey = config.textKey || 'name';
            select.dataset.filterIdKey = config.idKey || 'id';
            select.dataset.filterSearchKey = config.searchKey || config.textKey || 'name';
            if (config.dependsOn) select.dataset.filterDependsOn = config.dependsOn;
            if (config.dependsParam) select.dataset.filterDependsParam = config.dependsParam;
        }

        const emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = '';
        select.appendChild(emptyOption);

        if (config.type === 'static' && config.options) {
            config.options.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                select.appendChild(option);
            });
        }

        return select;
    }

    /**
     * P2 (rapport 2026-05-21) — resolve an active AJAX-filter value (IRI or
     * bare id) to its human-readable label and patch the Select2 option text,
     * so a restored filter shows e.g. "VIP" instead of "/api/tags/12" or "12".
     */
    async _resolveFilterOptionLabel(select, value, opt) {
        const base = (select.dataset.filterUrl || '').split('?')[0];
        if (!base || value === undefined || value === null || value === '') {
            return;
        }
        const textKey = select.dataset.filterTextKey || 'name';
        const raw = String(value);
        const iri = raw.startsWith('/') ? raw : `${base}/${raw}`;
        try {
            const label = await iriResolver.resolve(iri, textKey);
            if (label) {
                opt.textContent = label;
                this._refreshSelect2Label(select, label);
            }
        } catch {
            // Keep the raw value on failure — no worse than before.
        }
    }

    /**
     * Re-render a Select2 single selection after its option text changed.
     * `change.select2` alone does not always refresh the cached display, and
     * Select2 may not be initialised yet when the async label resolves — so we
     * also patch the rendered text directly and retry a couple of times.
     */
    _refreshSelect2Label(select, label) {
        if (!window.jQuery) return;
        const $ = window.jQuery;
        const apply = () => {
            const inst = $(select).data('select2');
            if (!inst) return false;
            $(select).trigger('change.select2');
            const container = inst.$container && inst.$container[0];
            const rendered = container ? container.querySelector('.select2-selection__rendered') : null;
            if (rendered) {
                rendered.setAttribute('title', label);
                // Drop existing text nodes, keep element children (clear button).
                Array.from(rendered.childNodes).forEach((n) => {
                    if (n.nodeType === Node.TEXT_NODE) {
                        n.textContent = '';
                    }
                });
                rendered.appendChild(document.createTextNode(label));
            }
            return true;
        };
        if (!apply()) {
            setTimeout(apply, 150);
            setTimeout(apply, 500);
        }
    }

    _initFilterSelect2() {
        if (!window.jQuery || !window.jQuery.fn.select2) return;

        const $ = window.jQuery;

        this.element.querySelectorAll('.dt-filter-select').forEach(select => {
            const config = {
                placeholder: this._getFilterPlaceholder(select),
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: select.dataset.filterType === 'api' ? 0 : Infinity,
            };

            if (select.dataset.filterType === 'api') {
                config.minimumInputLength = 1;
                config.language = {
                    inputTooShort: () => this.t('datatable.filter.input_too_short'),
                    searching: () => this.t('datatable.filter.searching'),
                    noResults: () => this.t('datatable.filter.no_results'),
                };
                config.ajax = {
                    url: select.dataset.filterUrl,
                    dataType: 'json',
                    delay: 300,
                    headers: {
                        'Accept': 'application/ld+json',
                        'Authorization': window.jwtToken ? `Bearer ${window.jwtToken}` : undefined,
                        ...(window.organizationSlug ? { 'X-ORGANIZATION': window.organizationSlug } : {}),
                    },
                    data: (params) => {
                        const query = {};
                        const searchParam = select.dataset.filterSearchKey || select.dataset.filterTextKey || 'name';
                        query[searchParam] = params.term || '';
                        query.size = 20;
                        this._applyDependentScope(select, query);
                        return query;
                    },
                    processResults: (data) => {
                        const items = data.member || data['hydra:member'] || [];
                        const idKey = select.dataset.filterIdKey || 'id';
                        const textKey = select.dataset.filterTextKey || 'name';
                        return {
                            results: items.map(item => ({
                                id: item[idKey] ?? item.id,
                                text: item[textKey] ?? String(item.id),
                            }))
                        };
                    },
                    cache: true,
                };
            }

            $(select).select2(config);

            $(select).on('change', () => {
                const param = select.dataset.filterParam;
                const value = select.value;

                if (value) {
                    this._activeFilters[param] = value;
                } else {
                    delete this._activeFilters[param];
                }

                // Reset any filter scoped to this one (e.g. task ← project)
                // before the reload below — no extra reload / SQL.
                this._clearDependentFilters(param, this.element);

                this.dataTable.ajax.reload();
            });
        });
    }

    _getFilterPlaceholder(select) {
        const param = select.dataset.filterParam;
        const filterConfig = this.filtersValue.find(f => f.param === param);
        return filterConfig?.placeholder || '';
    }

    /**
     * Dependent filter: append the live value of the parent filter
     * (`data-filter-depends-on`) to the autocomplete query under
     * `data-filter-depends-param`, so e.g. the task autocomplete is scoped to
     * the selected project. Reads the parent select's current value (works
     * before the mobile "Apply", falls back to the active filter). No extra
     * SQL — the param filters the autocomplete request already issued.
     * Cf. `docs/corrections/2026-06-14-3.md` P1.
     */
    _applyDependentScope(select, query) {
        const dependsOn = select.dataset.filterDependsOn;
        const dependsParam = select.dataset.filterDependsParam;
        if (!dependsOn || !dependsParam) return;

        const root = select.closest('.dt-mobile-filter-sheet') || this.element;
        const parent = root.querySelector(`[data-filter-param="${dependsOn}"]`);
        const parentValue = parent?.value || this._activeFilters[dependsOn];
        if (parentValue) query[dependsParam] = parentValue;
    }

    /**
     * When a parent filter changes, clear any filter that depends on it so a
     * value belonging to the previous parent (e.g. a task of another project)
     * is not kept. Runs inside the parent's existing change handler, before
     * its reload — no additional reload / SQL.
     */
    _clearDependentFilters(parentParam, root) {
        if (!window.jQuery) return;
        const $ = window.jQuery;

        root.querySelectorAll('.dt-filter-select, .dt-mobile-filter-select').forEach(child => {
            if (child.dataset.filterDependsOn !== parentParam || !child.value) return;
            $(child).val(null).trigger('change.select2');
            delete this._activeFilters[child.dataset.filterParam];
        });
    }

    _destroyFilters() {
        // Les écouteurs « clic extérieur » des filtres de plage vivent sur `document` : ils
        // survivraient au filtre qui les a posés, et la ligne de filtres se reconstruit à chaque
        // redraw. Les retirer ici est ce qui empêche l'accumulation.
        (this._dateRangeCleanups || []).forEach(cleanup => cleanup());
        this._dateRangeCleanups = [];

        if (!window.jQuery) return;
        const $ = window.jQuery;
        this.element.querySelectorAll('.dt-filter-select').forEach(select => {
            if ($(select).data('select2')) {
                $(select).select2('destroy');
            }
        });
    }

    _createDateRangeFilter(th, config) {
        // UN SEUL champ, et non deux inputs empilés : deux `<input type="date">` l'un sur
        // l'autre doublaient la hauteur de la ligne de filtres pour une colonne qui, la plupart
        // du temps, n'est pas filtrée. Le champ affiche la plage en clair et ouvre un popover
        // avec les deux bornes. Le binding reste `<param>[after]` / `<param>[before]`
        // (convention DateFilter d'API Platform) : rien ne change côté serveur.
        const wrapper = document.createElement('div');
        wrapper.className = 'dt-filter-daterange';

        const field = document.createElement('button');
        field.type = 'button';
        field.className = 'dt-filter-daterange__field';
        field.setAttribute('aria-haspopup', 'dialog');
        field.setAttribute('aria-expanded', 'false');

        // L'addon fait partie du champ, comme l'enveloppe d'un CustomEmailType : un champ de date
        // qui ne dit pas qu'il est une date se lit comme une zone de texte. Décoratif, donc
        // `aria-hidden` — le champ porte déjà le libellé de sa colonne.
        const addon = document.createElement('span');
        addon.className = 'dt-filter-daterange__addon';
        addon.setAttribute('aria-hidden', 'true');
        addon.innerHTML = '<i class="fa-regular fa-calendar"></i>';
        field.appendChild(addon);

        const label = document.createElement('span');
        label.className = 'dt-filter-daterange__value';
        field.appendChild(label);

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'dt-filter-daterange__clear';
        clearBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        clearBtn.title = this.t('datatable.filter_reset');
        clearBtn.setAttribute('aria-label', this.t('datatable.filter_reset'));

        const popover = document.createElement('div');
        popover.className = 'dt-filter-daterange__popover';
        popover.setAttribute('role', 'dialog');
        popover.hidden = true;

        const makeBound = (suffix, labelKey) => {
            const row = document.createElement('label');
            row.className = 'dt-filter-daterange__bound';

            const caption = document.createElement('span');
            caption.textContent = this.t(labelKey);
            row.appendChild(caption);

            const input = document.createElement('input');
            input.type = 'date';
            input.className = 'dt-filter-date dt-filter-date--range';
            input.dataset.filterParam = config.param + suffix;
            input.value = this._civilDateFromIso(this._activeFilters[input.dataset.filterParam]);
            row.appendChild(input);

            popover.appendChild(row);

            return input;
        };

        const fromInput = makeBound('[after]', 'datatable.daterange.from');
        const toInput = makeBound('[before]', 'datatable.daterange.to');

        // Le libellé dit la plage telle qu'elle est réellement bornée : une seule borne posée
        // donne « depuis le … » ou « jusqu'au … », pas une plage à moitié vide.
        const renderLabel = () => {
            const from = fromInput.value ? this._formatCivilDate(fromInput.value) : '';
            const to = toInput.value ? this._formatCivilDate(toInput.value) : '';

            if (from && to) {
                label.textContent = from + ' → ' + to;
            } else if (from) {
                label.textContent = this.t('datatable.daterange.since') + ' ' + from;
            } else if (to) {
                label.textContent = this.t('datatable.daterange.until') + ' ' + to;
            } else {
                label.textContent = this.t('datatable.daterange.placeholder');
            }

            const isSet = Boolean(from || to);
            field.classList.toggle('dt-filter-daterange__field--set', isSet);
            clearBtn.style.visibility = isSet ? 'visible' : 'hidden';
        };

        const apply = () => {
            for (const input of [fromInput, toInput]) {
                if (input.value) {
                    const isAfter = input.dataset.filterParam.endsWith('[after]');
                    this._activeFilters[input.dataset.filterParam] = this._dateRangeFilterValue(input.value, isAfter, config.granularity);
                } else {
                    delete this._activeFilters[input.dataset.filterParam];
                }
            }
            renderLabel();
            this.dataTable.ajax.reload();
        };

        const closePopover = () => {
            popover.hidden = true;
            field.setAttribute('aria-expanded', 'false');
        };

        // Le clic extérieur ferme, et l'écouteur se retire avec la table : un filtre reconstruit
        // à chaque redraw laisserait sinon autant d'écouteurs orphelins sur `document`.
        const onDocumentClick = (event) => {
            if (!wrapper.contains(event.target)) {
                closePopover();
            }
        };
        document.addEventListener('click', onDocumentClick);
        this._dateRangeCleanups = this._dateRangeCleanups || [];
        this._dateRangeCleanups.push(() => document.removeEventListener('click', onDocumentClick));

        field.addEventListener('click', () => {
            popover.hidden = !popover.hidden;
            field.setAttribute('aria-expanded', popover.hidden ? 'false' : 'true');
        });
        popover.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closePopover();
                field.focus();
            }
        });

        fromInput.addEventListener('change', apply);
        toInput.addEventListener('change', apply);
        clearBtn.addEventListener('click', () => {
            fromInput.value = '';
            toInput.value = '';
            apply();
        });

        renderLabel();

        wrapper.appendChild(field);
        wrapper.appendChild(clearBtn);
        wrapper.appendChild(popover);
        th.appendChild(wrapper);
    }

    /**
     * `YYYY-MM-DD` → `DD/MM/YYYY`.
     *
     * ⚠️ Le format est FIXE, il ne suit pas la langue de la table. `toLocaleDateString('en', …)`
     * rend `08/25/2026` : mêmes chiffres, ordre inverse — donc une date qu'un lecteur francophone
     * lit à l'envers sans rien voir d'anormal tant que le jour est ≤ 12. La règle du projet est
     * `DD/MM/YYYY HH:mm` partout, quelle que soit la langue de l'interface.
     */
    _formatCivilDate(civil) {
        const [year, month, day] = civil.split('-').map(Number);

        return `${this._pad2(day)}/${this._pad2(month)}/${year}`;
    }

    /** Deux chiffres, zéro devant : `5` → `05`. */
    _pad2(value) {
        return String(value).padStart(2, '0');
    }

    // ── Date timezone helpers (report 2026-05-23 § B.4) ────────
    //
    // <input type="date"> renders a "civil" date (YYYY-MM-DD) with no
    // timezone info. API Platform's DateFilter compares it against UTC
    // stored values. For a user in Europe/Paris (UTC+2 in summer) who
    // filters "from 2026-05-22", we must convert to "2026-05-21T22:00Z"
    // so a DB row at "2026-05-21T22:31Z" (= 00:31 the 22nd in Paris)
    // is included. Otherwise the user wonders why their morning event
    // doesn't appear.

    /**
     * Convert a "civil" date (YYYY-MM-DD from the local browser tz)
     * into the UTC ISO-8601 boundary corresponding to its local
     * start-of-day (`isAfter=true`) or end-of-day (`isAfter=false`).
     */
    _civilDateToUtcIso(civil, isAfter) {
        if (!civil) return '';
        const wallClock = isAfter ? 'T00:00:00.000' : 'T23:59:59.999';
        // `new Date('YYYY-MM-DDTHH:MM:SS.sss')` is interpreted as LOCAL time.
        // `.toISOString()` then converts to UTC.
        const d = new Date(`${civil}${wallClock}`);
        if (Number.isNaN(d.getTime())) return civil;
        return d.toISOString();
    }

    /**
     * Builds the value sent to the API for a date-range bound, honouring
     * the column granularity (cf. report 2026-06-14-2 § P2):
     *   - `'datetime'` columns (e.g. `createdAt`) — convert the civil date
     *     to a UTC instant so the API DateFilter respects the browser
     *     timezone (rapport 2026-05-23 § B.4).
     *   - `'date'` columns (default — `date_immutable`) — send the raw
     *     `YYYY-MM-DD`. Converting to UTC would shift the bound across a day
     *     boundary (the API binds the value as a date, dropping the time),
     *     making `after=15/06` wrongly include a row dated 14/06.
     */
    _dateRangeFilterValue(civil, isAfter, granularity) {
        return granularity === 'datetime'
            ? this._civilDateToUtcIso(civil, isAfter)
            : civil;
    }

    /**
     * Inverse of `_civilDateToUtcIso`: takes a UTC ISO string back to a
     * `YYYY-MM-DD` civil date in the browser's local timezone so the
     * <input type="date"> can restore it. Tolerates a raw `YYYY-MM-DD`
     * carry-over (older state from a pre-fix session).
     */
    _civilDateFromIso(iso) {
        if (!iso) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso;
        const d = new Date(iso);
        if (Number.isNaN(d.getTime())) return '';
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    // ── Columns ────────────────────────────────────────────────

    buildColumns() {
        // P0 (report 2026-05-23 § C.1) — numeric columns (currency,
        // number2, percent0) must be right-aligned with tabular-nums so
        // large amounts line up vertically for visual scan. We inject
        // the `dt-cell-numeric` class automatically; CSS handles the
        // rest — no need to pollute each DataTableConfigProvider.
        const NUMERIC_RENDERERS = new Set(['currency', 'number2', 'number0', 'percent0', 'erpStockQuantity']);
        const cols = this._columns.map(col => {
            const baseClass = col.className || '';
            const numericClass = NUMERIC_RENDERERS.has(col.render) ? 'dt-cell-numeric' : '';
            const combinedClass = [baseClass, numericClass].filter(Boolean).join(' ');
            return {
                data: col.data,
                title: col.title,
                render: col.render ? this.getRenderer(col.render) : null,
                responsivePriority: col.responsivePriority || 5,
                defaultContent: col.defaultContent || '',
                orderable: col.orderable !== false,
                searchable: col.searchable !== false,
                resolveField: col.resolveField || undefined,
                className: combinedClass || undefined,
                // Hidden by the user rather than removed: `visible(false)` keeps the column in
                // DataTables' index space, so no `meta.col`, no `sortField` and no filter cell has
                // to be recomputed when one is toggled.
                visible: this._isColumnVisible(col.data),
            };
        });

        // Prepend a checkbox column if any action opted into bulk mode.
        if (this._bulkActions.length > 0) {
            cols.unshift({
                data: null,
                // ⚠️ `this.t()` et non une chaîne en dur : ces deux `aria-label` étaient écrits
                // en français, donc lus en français par tout lecteur d'écran quelle que soit la
                // langue de la page. Ils sont les seuls libellés de ce fichier qu'un utilisateur
                // n'ENTEND que s'il ne peut pas voir la case — d'où l'oubli, et d'où sa gravité.
                title: `<input type="checkbox" class="dt-bulk-select-all" aria-label="${this._escHtml(this.t('datatable.bulk.select_all'))}">`,
                orderable: false,
                searchable: false,
                responsivePriority: 1,
                className: 'dt-bulk-checkbox-col',
                render: (data, type, row) =>
                    `<input type="checkbox" class="dt-bulk-select-row" data-id="${row.id}" aria-label="${this._escHtml(this.t('datatable.bulk.select_row'))}">`,
            });
        }

        if (this.actionsValue && this.actionsValue.length > 0) {
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                searchable: false,
                responsivePriority: 1,
                render: (data, type, row) => this.renderActions(row)
            });
        }

        return cols;
    }

    // ── Request / Response ─────────────────────────────────────

    transformRequestParams(d) {
        const params = {
            page: Math.floor(d.start / d.length) + 1,
            size: d.length
        };

        if (d.order && d.order.length > 0) {
            const orderCol = d.order[0];
            // The bulk checkbox is prepended as column 0 in DataTables but
            // doesn't exist in the column list — shift the index back so the
            // server receives the correct sort field.
            const columns = this._columns;
            const dataIdx = orderCol.column - this._bulkOffset;
            if (dataIdx >= 0 && dataIdx < columns.length) {
                const column = columns[dataIdx];
                if (column && column.data) {
                    const sortField = column.sortField || column.data;
                    params[`order[${sortField}]`] = orderCol.dir;
                }
            }
        }

        if (d.search && d.search.value) {
            params.search = d.search.value;
        }

        // Active filters
        for (const [param, value] of Object.entries(this._activeFilters || {})) {
            params[param] = value;
        }

        return params;
    }

    transformResponse(json) {
        let data = [];
        let total = 0;

        if (json['hydra:member']) {
            data = json['hydra:member'];
            total = json['hydra:totalItems'] || 0;
        } else if (json.member) {
            data = json.member;
            total = json.totalItems || 0;
        } else {
            data = json.data || [];
            total = json.total || data.length;
        }

        this.lastTotalItems = total;
        json.recordsTotal = total;
        json.recordsFiltered = total;
        json.data = data;

        return data;
    }

    handleError(xhr, error, code) {
        console.error('[DataTable] AJAX error:', error, code, xhr);
        document.dispatchEvent(new CustomEvent('ui:toast', {
            detail: { message: this.t('datatable.error.loading'), type: 'error' }
        }));
    }

    // ── Renderers ──────────────────────────────────────────────

    getRenderer(renderName) {
        const renderers = {
            userNameWithAvatar: (data, type, row) => {
                const initials = (row.firstName?.charAt(0) || '') + (row.lastName?.charAt(0) || '');
                const avatar = row.avatarPath
                    ? `<img src="/${row.avatarPath}" alt="" class="w-full h-full object-cover">`
                    : `<span>${initials || '?'}</span>`;
                return `
                    <div class="flex items-center gap-3">
                        <div class="w-7 h-7 rounded-full overflow-hidden bg-accent-100 flex items-center justify-center text-xs font-bold text-accent-600 flex-shrink-0">
                            ${avatar}
                        </div>
                        <a href="${this.userLinkPrefixValue}/${row.id}" class="font-medium text-slate-900 dark:text-slate-100 hover:text-accent-600 dark:hover:text-accent-400 transition-colors">
                            ${data}
                        </a>
                    </div>
                `;
            },
            statusBadge: (data, type, row) => data
                ? `<span class="badge badge-active">${this.t('datatable.status.active')}</span>`
                : `<span class="badge badge-inactive">${this.t('datatable.status.inactive')}</span>`,
            // Renders the column value as a link to the entity show page.
            // Reads the `linkPrefix` extra config from the column descriptor
            // (set server-side via `extra: ['linkPrefix' => '/admin/...']`)
            // and produces `<a href="{prefix}/{row.id}">{value}</a>`. The id
            // segment can target another serialized field via the `linkIdField`
            // extra (eg. `employeeId` to link a validation row to its employee's
            // time overview). Falls back to plain text when no prefix is
            // configured or the row has no id (eg. aggregation rows).
            nameLink: (data, type, row, meta) => {
                if (type !== 'display') return data;
                const dataIdx = meta.col - this._bulkOffset;
                const col = this._columns[dataIdx];
                const prefix = col?.linkPrefix || '';
                const id = row[col?.linkIdField || 'id'];
                if (!prefix || !id) return data;
                const safe = String(data ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return `<a href="${prefix}/${id}" class="font-medium text-slate-900 dark:text-slate-100 hover:text-accent-600 dark:hover:text-accent-400 transition-colors">${safe}</a>`;
            },
            iri: (data, type, row, meta) => {
                if (!data || typeof data !== 'string' || !data.startsWith('/api/')) {
                    return data || '—';
                }
                // `meta.col` is the DataTables column index, which is shifted
                // by 1 when the bulk checkbox column is prepended. Map it
                // back to the effective column list to read the correct
                // `resolveField`.
                const dataIdx = meta.col - this._bulkOffset;
                const field = this._columns[dataIdx]?.resolveField || 'name';
                const cached = iriResolver.get(data);
                if (cached) return cached[field] || '—';
                if (cached === null) return '—';
                return '<span class="text-slate-400 text-xs italic">…</span>';
            },
            userIri: (data, type, row, meta) => {
                if (!data || typeof data !== 'string' || !data.startsWith('/api/')) {
                    return data || '—';
                }
                const cached = iriResolver.get(data);
                if (cached === null) return '—';
                if (!cached) return '<span class="text-slate-400 text-xs italic">…</span>';
                const name = cached.fullName || `${cached.firstName || ''} ${cached.lastName || ''}`.trim() || '—';
                const initials = (cached.firstName?.charAt(0) || '') + (cached.lastName?.charAt(0) || '');
                const avatar = cached.avatarPath
                    ? `<img src="/${cached.avatarPath}" alt="" class="w-full h-full object-cover">`
                    : `<span>${initials || '?'}</span>`;
                return `
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full overflow-hidden bg-accent-100 flex items-center justify-center text-[10px] font-bold text-accent-600 flex-shrink-0">
                            ${avatar}
                        </div>
                        <span class="text-slate-900 dark:text-slate-100">${name}</span>
                    </div>
                `;
            },
            truncated: (data) => {
                if (!data) return '—';
                const str = String(data);
                return str.length > 60 ? `<span title="${str}">${str.substring(0, 60)}…</span>` : str;
            },
            monoCode: (data) => {
                if (!data) return '—';
                return `<span class="font-mono text-xs text-accent-600">${data}</span>`;
            },
            // ⚠️ `DD/MM/YYYY HH:mm`, FIXE — pas `dateStyle: 'short'`.
            //
            // Le format court d'`Intl` suit la langue de la table : en anglais, `8/25/26`. Mêmes
            // chiffres que `25/08/26`, ordre inverse, et rien à l'écran ne dit lequel des deux on
            // regarde — un `03/04/26` est illisible sans savoir quelle locale l'a rendu. La règle
            // du projet est un seul format, partout, quelle que soit la langue de l'interface.
            //
            // L'HEURE reste convertie dans le fuseau du navigateur : `new Date(iso)` le fait, et
            // c'est ce qu'on veut d'un horodatage — seule la MISE EN FORME est figée.
            date: (data) => {
                if (!data) return '—';
                const d = new Date(data);
                if (Number.isNaN(d.getTime())) return '—';

                return `${this._pad2(d.getDate())}/${this._pad2(d.getMonth() + 1)}/${d.getFullYear()}`
                    + ` ${this._pad2(d.getHours())}:${this._pad2(d.getMinutes())}`;
            },
            // P11 (report 2026-05-31) — for pure DATE columns (Doctrine
            // `date_immutable`: issueDate, dueDate, deliveryDate…) format the
            // calendar date only, WITHOUT timezone conversion. `new Date(iso)`
            // parses a midnight-UTC value and shifts it locally (→ "02:00" in
            // UTC+2, sometimes the previous day). Building the date from the
            // Y-M-D parts keeps it stable and drops the meaningless time.
            dateOnly: (data) => {
                if (!data) return '—';
                const m = String(data).match(/^(\d{4})-(\d{2})-(\d{2})/);
                if (m) return `${m[3]}/${m[2]}/${m[1]}`;

                const d = new Date(data);
                if (Number.isNaN(d.getTime())) return '—';

                // ⚠️ Même format fixe que `date`, sans l'heure : une échéance n'en a pas, et en
                // afficher une laisserait croire à une précision que la donnée n'a pas.
                return `${this._pad2(d.getDate())}/${this._pad2(d.getMonth() + 1)}/${d.getFullYear()}`;
            },
            colorSwatch: (data) => {
                if (!data) return '—';
                const safe = /^#[0-9a-fA-F]{3,8}$/.test(String(data)) ? data : '#64748b';
                return `<div class="flex items-center gap-2">
                    <span class="inline-block w-4 h-4 rounded-full border border-slate-300 dark:border-slate-600" style="background-color: ${safe};"></span>
                    <span class="font-mono text-xs text-slate-600 dark:text-slate-400">${data}</span>
                </div>`;
            },
            booleanBadge: (data) => {
                if (data === true || data === 'true' || data === 1 || data === '1') {
                    return `<span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"><i class="fa-solid fa-check text-xs"></i></span>`;
                }
                return `<span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-700 dark:text-slate-500"><i class="fa-solid fa-minus text-xs"></i></span>`;
            },
            // Active/inactive status — unlike the neutral booleanBadge (a grey
            // dash for `false`), an INACTIVE row is rendered with a clearly
            // distinct red "ban" pill so deactivated products stand out in every
            // listing (product index, parent variants, collection products).
            // Rapport 2026-06-06-3 § P4. Icon-only (no hardcoded text); the
            // column header carries the meaning. `t()` provides an accessible title.
            activeBadge: (data) => {
                const active = data === true || data === 'true' || data === 1 || data === '1';
                if (active) {
                    return `<span title="${this.t('datatable.status.active')}" class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"><i class="fa-solid fa-check text-xs"></i></span>`;
                }
                return `<span title="${this.t('datatable.status.inactive')}" class="inline-flex h-5 w-5 items-center justify-center  rounded-full px-2 py-0.5 text-[11px] font-medium bg-red-100 text-red-700 ring-1 ring-red-200 dark:bg-red-900/40 dark:text-red-300 dark:ring-red-800"><i class="fa-solid fa-ban text-[10px]"></i></span>`;
            },
            number0: (data) => {
                if (data === null || data === undefined || data === '') return '—';
                const n = Number(data);
                if (!Number.isFinite(n)) return '—';
                return Math.round(n).toLocaleString('fr-FR');
            },
            // Pure date (no time part) — for `date_immutable` columns where
            // the TZ-aware datetime format would inject a misleading "01:00".
            // Cf. docs/corrections/2026-05-23-4.md § #3 (bonus).
            number2: (data) => {
                if (data === null || data === undefined || data === '') return '—';
                const n = Number(data);
                if (!Number.isFinite(n)) return '—';
                return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
            // P21 — stock quantity with a red warning badge when negative
            // (oversell). The hard block stays governed server-side by
            // `erp.order.allow_oversell`; this only surfaces the state.
            percent0: (data) => {
                if (data === null || data === undefined || data === '') return '—';
                const n = Number(data);
                if (!Number.isFinite(n)) return '—';
                return `${Math.round(n).toLocaleString('fr-FR')} %`;
            },
            // P7 (rapport 2026-05-18) — string[] of labels rendered as
            // a series of badges. Used for CRM tag labels.
            chipList: (data) => {
                if (!Array.isArray(data) || data.length === 0) {
                    return '<span class="text-slate-400">—</span>';
                }
                const escape = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                return data.map((s) =>
                    `<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-accent-50 text-accent-700 ring-1 ring-accent-200 dark:bg-accent-900/30 dark:text-accent-300 dark:ring-accent-800 mr-1 mb-0.5">${escape(s)}</span>`
                ).join('');
            },
            fileSize: (data) => {
                if (data === null || data === undefined) return '—';
                const bytes = Number(data);
                if (!Number.isFinite(bytes) || bytes < 0) return '—';
                if (bytes < 1024) return `${bytes} B`;
                if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
                return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
            },
            durationMs: (data) => {
                if (data === null || data === undefined) return '—';
                const ms = Number(data);
                if (!Number.isFinite(ms) || ms < 0) return '—';
                if (ms < 1000) return `${ms} ms`;
                return `${(ms / 1000).toFixed(2)} s`;
            },
            currency: (data, type, row, meta) => {
                if (data === null || data === undefined || data === '') return '—';
                const n = Number(data);
                if (!Number.isFinite(n)) return '—';
                const col = this._columns[meta.col - this._bulkOffset];
                const suffix = row?.currency || col?.suffix || '';
                const formatted = n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                return suffix ? `${formatted} ${suffix}` : formatted;
            },
            // Plain numbers without suffix (quantities, raw subtotals).
            country: (data) => {
                if (!data) return '—';
                const code = String(data).toUpperCase();
                return this.countryNamesValue[code] || code;
            },
        };

        if (renderers[renderName]) {
            return renderers[renderName];
        }

        // Business badges — `erpQuoteStatusBadge`, `sirhExpenseStatus`, `deliveryTourStatusBadge`…
        // — are the application's vocabulary, not the table's. They are registered from the
        // application side and resolved here. Cf. `registerRenderers()` in `../renderers`.
        const factory = getRegisteredRenderers()[renderName];

        return factory ? factory(this) : null;
    }

    // ── Bulk selection ─────────────────────────────────────────

    _wireBulkCheckboxes() {
        if (this._bulkActions.length === 0) {
            return;
        }
        const container = this.element.closest('.dt-container') || this.element;

        // Row checkboxes — re-wire on every redraw because DataTables
        // re-renders the tbody from scratch.
        container.querySelectorAll('.dt-bulk-select-row').forEach((cb) => {
            const id = cb.dataset.id;
            cb.checked = this._bulkSelection.has(id);
            // P4 feedback (2026-05-18) — the bulk-checkbox sits in the
            // same TD as `dtr-control`, so a click bubbles up and
            // triggers DataTables Responsive's row-expand handler.
            // Stop propagation so ticking the checkbox stays a pure
            // bulk-selection action.
            cb.addEventListener('click', (event) => {
                event.stopPropagation();
            });
            cb.addEventListener('change', () => {
                if (cb.checked) this._bulkSelection.add(id);
                else this._bulkSelection.delete(id);
                this._renderBulkBar();
                this._syncSelectAllCheckbox();
            });
        });

        // Header "select all" — toggles every visible row.
        const selectAll = container.querySelector('.dt-bulk-select-all');
        if (selectAll && !selectAll.dataset.bulkBound) {
            selectAll.dataset.bulkBound = 'true';
            selectAll.addEventListener('change', () => {
                container.querySelectorAll('.dt-bulk-select-row').forEach((cb) => {
                    cb.checked = selectAll.checked;
                    const id = cb.dataset.id;
                    if (selectAll.checked) this._bulkSelection.add(id);
                    else this._bulkSelection.delete(id);
                });
                this._renderBulkBar();
            });
        }

        this._syncSelectAllCheckbox();
    }

    _syncSelectAllCheckbox() {
        const container = this.element.closest('.dt-container') || this.element;
        const selectAll = container.querySelector('.dt-bulk-select-all');
        if (!selectAll) return;

        const rowBoxes = [...container.querySelectorAll('.dt-bulk-select-row')];
        if (rowBoxes.length === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
        }
        const checkedCount = rowBoxes.filter(cb => cb.checked).length;
        selectAll.checked = checkedCount === rowBoxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < rowBoxes.length;
    }

    _renderBulkBar() {
        if (this._bulkActions.length === 0) return;

        const container = this.element.closest('.panel') || this.element.parentElement;
        if (!container) return;

        let bar = container.querySelector('.dt-bulk-bar');
        const count = this._bulkSelection.size;

        if (count === 0) {
            if (bar) bar.classList.add('hidden');
            return;
        }

        if (!bar) {
            bar = this._buildBulkBar();
            container.prepend(bar);
        }

        bar.classList.remove('hidden');
        bar.querySelector('[data-bulk-count]').textContent =
            this.t('datatable.bulk.selected').replace('%count%', count);
    }

    _buildBulkBar() {
        const bar = document.createElement('div');
        bar.className = 'dt-bulk-bar';

        const options = this._bulkActions
            .map(a => `<option value="${this._escAttr(a.type)}">${this._escAttr(a.bulkLabel || a.label)}</option>`)
            .join('');

        // Stimulus auto-connects `ui--select2` once the markup lands in
        // the DOM. An empty `<option value="">` is required for Select2
        // to switch to placeholder mode; allowClear is off because the
        // bulk bar only makes sense when an action is picked.
        bar.innerHTML = `
            <span class="dt-bulk-bar__count" data-bulk-count></span>
            <span class="dt-bulk-select__container">
                <select class="dt-bulk-bar__select"
                        data-bulk-select
                        data-controller="ui--select2"
                        data-ui--select2-placeholder-value="${this._escAttr(this.t('datatable.bulk.choose'))}"
                        data-ui--select2-allow-clear-value="false">
                    <option value=""></option>
                    ${options}
                </select>
            </span>
            <button type="button" class="btn-primary dt-bulk-bar__apply" data-bulk-apply>
                ${this._escAttr(this.t('datatable.bulk.apply'))}
            </button>
            <button type="button" class="dt-bulk-bar__clear" data-bulk-clear>
                ${this._escAttr(this.t('datatable.bulk.clear'))}
            </button>
        `;

        bar.querySelector('[data-bulk-apply]').addEventListener('click', () => this._applyBulkAction(bar));
        bar.querySelector('[data-bulk-clear]').addEventListener('click', () => this._clearBulkSelection());

        return bar;
    }

    _clearBulkSelection() {
        this._bulkSelection.clear();
        const container = this.element.closest('.dt-container') || this.element;
        container.querySelectorAll('.dt-bulk-select-row').forEach(cb => { cb.checked = false; });
        this._renderBulkBar();
        this._syncSelectAllCheckbox();
    }

    _applyBulkAction(bar) {
        const select = bar.querySelector('[data-bulk-select]');
        const actionType = select.value;
        if (!actionType) return;

        const action = this._bulkActions.find(a => a.type === actionType);
        if (!action) return;

        const ids = [...this._bulkSelection];
        if (ids.length === 0) return;

        const count = ids.length;

        // Build a hidden form with ids[] + CSRF. Rather than routing
        // through the Stimulus `ui--modal` controller (which needs time
        // to connect to a freshly-mounted element), we run the modal
        // dialog ourselves — the UX stays identical since we reuse the
        // same DOM template the controller builds.
        const form = document.createElement('form');
        form.method = 'post';
        form.action = action.bulkRoute;
        form.style.display = 'none';

        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            form.appendChild(input);
        });

        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = '_token';
        csrf.value = this._bulkCsrfToken();
        form.appendChild(csrf);

        document.body.appendChild(form);

        this._showBulkConfirmDialog({
            variant: action.variant || 'danger',
            title:   this._bulkModalText(action, 'title', count),
            message: this._bulkModalText(action, 'message', count),
            confirm: this._bulkModalText(action, 'confirm', count),
            cancel:  this.t('datatable.confirm.cancel'),
            // Optional extra field (e.g. the owner picker for bulk-assign,
            // audit § P29): {name, label, options:[{value,label}]}.
            input:   action.bulkInput || null,
            onConfirm: (inputValue) => {
                if (action.bulkInput) {
                    const field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = action.bulkInput.name;
                    field.value = inputValue;
                    form.appendChild(field);
                }
                form.submit();
            },
            onCancel:  () => form.remove(),
        });
    }

    _showBulkConfirmDialog({ variant, title, message, confirm, cancel, onConfirm, onCancel, input = null }) {
        const iconBg = {
            danger:  'bg-red-100 dark:bg-red-900/40',
            warning: 'bg-amber-100 dark:bg-amber-900/40',
            primary: 'bg-accent-100 dark:bg-accent-900/40',
        }[variant] || 'bg-red-100 dark:bg-red-900/40';

        const iconColor = {
            danger:  'text-red-600',
            warning: 'text-amber-600',
            primary: 'text-accent-600',
        }[variant] || 'text-red-600';

        const btnClass = {
            danger:  'btn-danger',
            warning: 'btn-warning',
            primary: 'btn-primary',
        }[variant] || 'btn-danger';

        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center p-4';
        overlay.innerHTML = `
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" data-backdrop></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-slate-200 dark:border-slate-700">
                <div class="flex items-start gap-4">
                    <div class="${iconBg} rounded-full p-2.5 flex-shrink-0">
                        <i class="fa-solid fa-triangle-exclamation text-xl ${iconColor}"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        ${title ? `<h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">${this._escAttr(title)}</h3>` : ''}
                        ${message ? `<p class="text-sm text-slate-600 dark:text-slate-400">${this._escAttr(message)}</p>` : ''}
                        ${input ? this._bulkInputMarkup(input) : ''}
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" data-cancel class="btn-secondary">${this._escAttr(cancel)}</button>
                    <button type="button" data-confirm class="${btnClass}">${this._escAttr(confirm)}</button>
                </div>
            </div>
        `;

        const close = (cb, arg) => {
            overlay.remove();
            if (cb) cb(arg);
        };
        overlay.querySelector('[data-backdrop]').addEventListener('click', () => close(onCancel));
        overlay.querySelector('[data-cancel]').addEventListener('click', () => close(onCancel));
        overlay.querySelector('[data-confirm]').addEventListener('click', () => {
            // A picker input (e.g. bulk-assign owner) is required — block on empty.
            const field = overlay.querySelector('[data-bulk-input]');
            if (field) {
                if (!field.value) {
                    field.classList.add('ring-2', 'ring-red-400');

                    return;
                }
                close(onConfirm, field.value);

                return;
            }
            close(onConfirm);
        });
        document.body.appendChild(overlay);
    }

    /** Renders the optional picker (owner select) inside the bulk dialog (audit § P29). */
    _bulkInputMarkup(input) {
        const opts = (input.options || [])
            .map(o => `<option value="${this._escAttr(String(o.value))}">${this._escAttr(String(o.label))}</option>`)
            .join('');

        return `
            <div class="mt-4">
                ${input.label ? `<label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">${this._escAttr(input.label)}</label>` : ''}
                <select data-bulk-input class="form-control w-full">
                    <option value="">${this._escAttr(input.placeholder || '—')}</option>
                    ${opts}
                </select>
            </div>
        `;
    }

    _bulkModalText(action, key, count) {
        const Key = key.charAt(0).toUpperCase() + key.slice(1);

        const override = action[`modalBulk${Key}`];
        if (override) return String(override).replace(/%count%/g, String(count));

        const specificKey = `datatable.modal.bulk.${action.type}.${key}`;
        const specific = this.t(specificKey);
        if (specific && specific !== specificKey) {
            return String(specific).replace(/%count%/g, String(count));
        }

        // Last resort: the per-row text. It may carry `{field}` placeholders that
        // only make sense for a single row — there is none here, so strip them
        // rather than leak "{firstName} {lastName}" to the user
        // (rapport 2026-08-05 § P1). Declaring the action type in
        // DatatableBulkExtension::ACTION_TYPES is what avoids landing here.
        return String(this._modalText(action, key))
            .replace(/%count%/g, String(count))
            .replace(/\s*\{[a-zA-Z_]\w*\}/g, '');
    }

    _bulkCsrfToken() {
        // Passed from the Twig template via `data-core--datatable-bulk-csrf-value`.
        return this.bulkCsrfValue || '';
    }

    // ── Actions ────────────────────────────────────────────────

    renderActions(row) {
        const items = this.actionsValue
            // `bulkOnly` actions (e.g. bulk-assign, audit § P29) have no per-row button.
            .filter(action => !action.bulkOnly)
            .filter(action => !action.condition || this.evaluateCondition(action.condition, row))
            .map(action => ({
                ...action,
                icon: this.getFaIcon(action.icon),
                label: action.label || `Action ${action.type}`,
                url: this.generateUrl(action.route, row),
                // Kept for per-row interpolation of the confirm-modal texts
                // ({firstName}, {lastName}, … placeholders in modalTitle/…).
                row,
            }));

        if (items.length === 0) return '';

        return this.actionsDisplayValue === 'dropdown'
            ? this._renderActionsDropdown(items)
            : this._renderActionsInline(items);
    }

    _renderActionsInline(items) {
        const html = items.map(item => {
            const classes = `action-btn ${item.class || 'text-slate-500 hover:text-accent-600 dark:text-slate-400 dark:hover:text-accent-400'}`;
            if (item.method === 'POST') {
                return this._renderPostForm(item, `
                    <button type="submit" class="${classes}"
                            data-controller="ui--tooltip"
                            data-ui--tooltip-text-value="${item.label}"
                            title="${item.label}"
                            aria-label="${item.label}">
                        ${item.icon}
                    </button>`);
            }
            return `<a href="${item.url}" class="${classes}"
                       data-controller="ui--tooltip"
                       data-ui--tooltip-text-value="${item.label}"
                       title="${item.label}"
                       aria-label="${item.label}">${item.icon}</a>`;
        });
        return `<div class="flex items-center justify-end gap-1">${html.join('')}</div>`;
    }

    _renderActionsDropdown(items) {
        const id = 'dd-' + Math.random().toString(36).substring(2, 8);
        const menuItems = items.map(item => {
            const colorClass = item.class?.replace('hover:', '').split(' ').find(c => c.startsWith('text-')) || 'text-slate-700 dark:text-slate-300';
            const hoverClass = item.class?.split(' ').find(c => c.startsWith('hover:')) || 'hover:bg-slate-50 dark:hover:bg-slate-700';
            const classes = `flex items-center gap-2 w-full px-3 py-2 text-sm ${colorClass} ${hoverClass} transition-colors`;

            if (item.method === 'POST') {
                return this._renderPostForm(item, `
                    <button type="submit" class="${classes}">
                        <span class="w-4 text-center">${item.icon}</span>
                        ${item.label}
                    </button>`);
            }
            return `<a href="${item.url}" class="${classes}">
                        <span class="w-4 text-center">${item.icon}</span>
                        ${item.label}
                    </a>`;
        });

        return `
            <div class="relative dt-dropdown" data-id="${id}">
                <button type="button" class="action-btn text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-300" onclick="window._toggleDtDropdown(this)">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                </button>
                <div class="dt-dropdown-menu hidden fixed w-48 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xl py-1 overflow-hidden" style="z-index: 9;">
                    ${menuItems.join('')}
                </div>
            </div>`;
    }

    _renderPostForm(item, buttonHtml) {
        const tokenInput = this.singleCsrfValue
            ? `<input type="hidden" name="_token" value="${this._escAttr(this.singleCsrfValue)}">`
            : '';

        return `
            <form method="post" action="${item.url}" class="inline"
                  data-controller="ui--modal"
                  data-action="submit->ui--modal#intercept"
                  data-ui--modal-title-value="${this._escAttr(this._modalText(item, 'title'))}"
                  data-ui--modal-message-value="${this._escAttr(this._modalText(item, 'message'))}"
                  data-ui--modal-confirm-label-value="${this._escAttr(this._modalText(item, 'confirm'))}"
                  data-ui--modal-cancel-label-value="${this._escAttr(this.t('datatable.confirm.cancel'))}"
                  data-ui--modal-confirm-variant-value="${item.variant || 'danger'}">
                ${tokenInput}
                ${buttonHtml}
            </form>`;
    }

    _modalText(action, key) {
        // Priority: per-action override > action-type specific key > generic fallback.
        // `this.t()` returns the raw key when missing, so each step checks for
        // a real hit before falling through — otherwise "datatable.modal.delete.title"
        // leaks into the UI as literal text. Resolved texts may carry `{field}`
        // placeholders interpolated from the row (same syntax as generateUrl),
        // so a confirm modal can name the affected record (2026-07-13-2, modale
        // « Activer l'espace personnel »).
        const override = action[`modal${key.charAt(0).toUpperCase() + key.slice(1)}`];
        if (override) return this._resolveModalText(override, action.row);

        const specificKey = `datatable.modal.${action.type}.${key}`;
        const specific = this.t(specificKey);
        if (specific && specific !== specificKey) return this._resolveModalText(specific, action.row);

        const fallbackKey = `datatable.confirm.${key}`;
        const fallback = this.t(fallbackKey);
        return fallback && fallback !== fallbackKey ? this._resolveModalText(fallback, action.row) : '';
    }

    /**
     * Fills a resolved modal text: `{field}` placeholders from the row (nominative
     * overrides), then `%subject%` with the row's label.
     */
    _resolveModalText(text, row) {
        return this._interpolateRow(text, row).replace(/%subject%/g, this._subjectLabel(row));
    }

    _interpolateRow(text, row) {
        if (!row) return text;
        return text.replace(/\{(\w+)\}/g, (match, key) => row[key] ?? match);
    }

    /**
     * Human label for the record a confirm modal is about, so a generic message
     * can name it instead of falling back to the neutral "this item" wording
     * (rapport 2026-08-05 § P2). Ordered probe, same spirit as
     * NotificationService::buildTranslationParams server-side (getFullName then
     * getName). Returns null when nothing usable is on the row.
     */
    _rowSubject(row) {
        if (!row) return null;
        if (this.subjectFieldValue && row[this.subjectFieldValue]) {
            return String(row[this.subjectFieldValue]);
        }
        const probe = ['fullName', 'name', 'title', 'label', 'number', 'reference',
                       'code', 'employeeNumber', 'sku', 'subject', 'email'];
        for (const key of probe) {
            if (row[key]) return String(row[key]);
        }
        const composed = `${row.firstName || ''} ${row.lastName || ''}`.trim();
        return composed || null;
    }

    /** Wrapped subject for `%subject%`, or the generic fallback label. */
    _subjectLabel(row) {
        const subject = this._rowSubject(row);
        if (null === subject) return this.t('datatable.modal.subject_default');

        return this.t('datatable.modal.subject_wrap').replace('%subject%', subject);
    }

    _escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    evaluateCondition(condition, row) {
        try {
            const context = { row, user: window.currentUser || {} };
            return new Function('context', `with(context) { return ${condition}; }`)(context);
        } catch (e) {
            console.warn('[DataTable] Condition evaluation error:', condition, e);
            return false;
        }
    }

    generateUrl(route, row) {
        return route.replace(/\{(\w+)\}/g, (match, key) => row[key] ?? match);
    }

    getFaIcon(name) {
        const icons = {
            eye: '<i class="fa-solid fa-eye"></i>',
            pencil: '<i class="fa-solid fa-pen-to-square"></i>',
            'user-circle': '<i class="fa-solid fa-user-secret"></i>',
            ban: '<i class="fa-solid fa-ban"></i>',
            'check-circle': '<i class="fa-solid fa-circle-check"></i>',
            'circle-check': '<i class="fa-solid fa-circle-check"></i>',
            trash: '<i class="fa-solid fa-trash-can"></i>',
            'rotate-left': '<i class="fa-solid fa-rotate-left"></i>',
            shuffle: '<i class="fa-solid fa-shuffle"></i>',
            trophy: '<i class="fa-solid fa-trophy"></i>',
            'money-bill': '<i class="fa-solid fa-money-bill-1"></i>',
            'money-check-dollar': '<i class="fa-solid fa-money-check-dollar"></i>',
            'paper-plane': '<i class="fa-solid fa-paper-plane"></i>',
            'file-invoice': '<i class="fa-solid fa-file-invoice"></i>',
            'truck-fast': '<i class="fa-solid fa-truck-fast"></i>',
            columns: '<i class="fa-solid fa-table-columns"></i>',
            'table-columns': '<i class="fa-solid fa-table-columns"></i>',
            'folder-open': '<i class="fa-solid fa-folder-open"></i>',
            // P1 (rapport 2026-05-23-4 §§ #4/#7) — detach actions.
            unlink: '<i class="fa-solid fa-link-slash"></i>',
            // SIRH datatable lifecycle actions (rapport 2026-06-18-2 P4).
            check: '<i class="fa-solid fa-check"></i>',
            xmark: '<i class="fa-solid fa-xmark"></i>',
            clock: '<i class="fa-solid fa-clock"></i>',
            rocket: '<i class="fa-solid fa-rocket"></i>',
            bullhorn: '<i class="fa-solid fa-bullhorn"></i>',
            lock: '<i class="fa-solid fa-lock"></i>',
            // Employee offboarding (« Sortir ») — rapport 2026-06-21 P7.
            'right-from-bracket': '<i class="fa-solid fa-right-from-bracket"></i>',
        };
        return icons[name] || '<i class="fa-solid fa-circle-question"></i>';
    }

    // ── Mobile Card View ────────────────────────────────────────

    _renderCards() {
        const container = this.element.closest('.dt-container');
        if (!container) return;

        if (!this._cardContainer) {
            this._cardContainer = document.createElement('div');
            this._cardContainer.className = 'dt-card-list hidden';
            // Insert after the table wrapper
            const tableWrapper = container.querySelector('.dt-scroll, table');
            if (tableWrapper) {
                tableWrapper.parentNode.insertBefore(this._cardContainer, tableWrapper.nextSibling);
            } else {
                container.appendChild(this._cardContainer);
            }
        }

        if (!this._mqListener) {
            this._mq = window.matchMedia('(max-width: 1024px)');
            this._mqListener = () => this._toggleCardView();
            this._mq.addEventListener('change', this._mqListener);
        }

        // Build cards from current page data
        const rows = this.dataTable.rows({ page: 'current' }).data().toArray();
        const columns = this._visibleColumns;

        if (rows.length === 0) {
            this._cardContainer.innerHTML = `
                <div class="p-8 text-center text-sm text-slate-400">
                    <i class="fa-solid fa-inbox text-3xl mb-2 text-slate-300"></i>
                    <p>${this.getLanguageConfig().emptyTable || 'No data'}</p>
                </div>`;
        } else {
            this._cardContainer.innerHTML = rows.map(row => this._renderCard(row, columns)).join('');
        }

        this._toggleCardView();
    }

    _renderCard(row, columns) {
        // `meta.col` must be a DATATABLES column index: the renderers that read a descriptor from
        // it subtract the bulk offset and index the effective column list. Handing them the
        // position within the card's own column subset pointed at the wrong descriptor — or at
        // -1 on a table with bulk actions.
        //
        // ⚠️ The index is found BY KEY, never by object identity. `_columns` is a getter over
        // `this.columnsValue`, and a Stimulus value getter RE-PARSES its JSON attribute on every
        // read: two accesses yield equal objects that are never `===`. `indexOf(col)` therefore
        // returned -1 for every column, `meta.col` collapsed to the bulk offset, and each renderer
        // read the descriptor of column -1 — undefined. Renderers that fall back to a default
        // (`iri` → `resolveField: 'name'`) kept working by luck, so the defect only surfaced on the
        // first table whose `resolveField` was something else: an asset column resolving `label`
        // showed a dash on mobile while the desktop table showed the value (wovex, ADR-0010).
        const all = this._columns;
        const metaCol = (col) => ({ col: all.findIndex(c => c.data === col.data) + this._bulkOffset });

        // Find the "primary" column (responsivePriority 1 or first column)
        const primaryCol = columns.find(c => c.responsivePriority === 1) || columns[0];
        const primaryValue = row[primaryCol.data];
        const primaryRenderer = primaryCol.render ? this.getRenderer(primaryCol.render) : null;
        const primaryHtml = primaryRenderer
            ? primaryRenderer(primaryValue, 'display', row, metaCol(primaryCol))
            : this._escHtml(primaryValue);

        // Secondary fields (skip primary, skip hidden-priority columns)
        const secondaryFields = columns
            .filter(c => c.data !== primaryCol.data && c.data !== 'id')
            .map(c => {
                const value = row[c.data];
                if (value === null || value === undefined || value === '') return null;
                const renderer = c.render ? this.getRenderer(c.render) : null;
                const html = renderer
                    ? renderer(value, 'display', row, metaCol(c))
                    : this._escHtml(value);
                return { title: c.title, html };
            })
            .filter(Boolean);

        // Actions
        const actionsHtml = this.renderActions(row);

        // Soft-deleted rows get the same red treatment as the desktop
        // table — the desktop variant uses a `<tr>` class but the card
        // layout has its own block, so both surfaces stay visually
        // consistent for super-admins reviewing deleted entities.
        const deletedClass = row && row.deletedAt ? ' dt-card--deleted' : '';

        return `
            <div class="dt-card${deletedClass}">
                <div class="dt-card-header">
                    <div class="dt-card-primary">${primaryHtml}</div>
                    ${actionsHtml ? `<div class="dt-card-actions">${actionsHtml}</div>` : ''}
                </div>
                <div class="dt-card-body">
                    ${secondaryFields.map(f => `
                        <div class="dt-card-field">
                            <span class="dt-card-label">${f.title}</span>
                            <span class="dt-card-value">${f.html}</span>
                        </div>
                    `).join('')}
                </div>
            </div>`;
    }

    _toggleCardView() {
        const container = this.element.closest('.dt-container');
        if (!container || !this._cardContainer) return;

        // Card view kicks in below the "large" breakpoint (1024px) because
        // the ~800–1100px range squashes the full table layout awkwardly.
        // Above 1024px the table + DataTables responsive plugin handle
        // everything cleanly.
        const isCompact = window.matchMedia('(max-width: 1024px)').matches;
        const scrollWrapper = container.querySelector('.dt-scroll') || this.element;

        if (isCompact) {
            scrollWrapper.classList.add('dt-desktop-only');
            this._cardContainer.classList.remove('hidden');
        } else {
            scrollWrapper.classList.remove('dt-desktop-only');
            this._cardContainer.classList.add('hidden');
        }
    }

    _escHtml(val) {
        if (val === null || val === undefined) return '—';
        const div = document.createElement('div');
        div.textContent = String(val);
        return div.innerHTML;
    }

    /**
     * DataTables' own strings, read from the catalogue like everything else.
     *
     * ⚠️ This used to be a `{ fr: {…}, en: {…} }` table written in JavaScript, with eleven French
     * sentences hard-coded in it and English left almost empty. A product serving five locales —
     * `cegeta` does — fell back to a half-filled English for three of them, and nothing said so.
     * A table of languages inside a bundle has no business knowing how many languages a product
     * speaks: the server has the translator.
     *
     * ⚠️ `_MENU_`, `_START_`, `_END_`, `_TOTAL_` and `_MAX_` are DataTables' own placeholders,
     * substituted by the library long after the translator is done. They travel inside the
     * translated string and must survive translation untouched.
     *
     * The pagination arrows stay here: they are icons, not sentences.
     */
    getLanguageConfig() {
        return {
            processing: this.t('datatable.dt.processing'),
            search: this.t('datatable.dt.search'),
            lengthMenu: this.t('datatable.dt.length_menu'),
            info: this.t('datatable.dt.info'),
            infoEmpty: this.t('datatable.dt.info_empty'),
            infoFiltered: this.t('datatable.dt.info_filtered'),
            loadingRecords: this.t('datatable.dt.loading'),
            zeroRecords: this.t('datatable.dt.zero_records'),
            emptyTable: this.t('datatable.dt.empty_table'),
            paginate: {
                first: '<i class="fa-solid fa-angles-left"></i>',
                previous: '<i class="fa-solid fa-angle-left"></i>',
                next: '<i class="fa-solid fa-angle-right"></i>',
                last: '<i class="fa-solid fa-angles-right"></i>'
            },
            aria: {
                sortAscending: this.t('datatable.dt.aria.sort_ascending'),
                sortDescending: this.t('datatable.dt.aria.sort_descending')
            }
        };
    }
}
