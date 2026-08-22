/**
 * The registry of cell renderers the table looks up by name.
 *
 * A column declares `render: 'erpQuoteStatusBadge'` server-side, and the table asks for a function
 * of that name at draw time. The generic ones — dates, numbers, IRIs, the active/inactive badge —
 * live in the controller itself. Everything that carries business vocabulary is registered here by
 * the application:
 *
 * ```js
 * // assets/datatable/renderers.js, imported once from the entry point
 * import { registerRenderers, badge } from '@jul6art/datatable-bundle/renderers';
 *
 * registerRenderers({
 *     erpQuoteStatusBadge: badge('datatable.erp_quote_status', {
 *         draft: 'slate', sent: 'sky', accepted: 'emerald', rejected: 'red',
 *     }),
 * });
 * ```
 *
 * ## Why a registry rather than a longer controller
 *
 * `draft / sent / accepted / rejected` is one application's quote workflow. Shipping it in a
 * bundle would ship that application's domain model to every other one — the same reason the
 * permission catalogue and the status-label maps stayed in the project.
 *
 * ## The shape: a factory, not a function
 *
 * An entry is `(controller) => (data, type, row, meta) => string`. The extra hop exists because a
 * renderer needs the controller — `controller.t()` for its labels, `controller.columnsValue` to
 * read its own column's configuration — and an arrow function has no `this` to bind.
 *
 * ```js
 * registerRenderers({
 *     invoiceNumber: (c) => (data, type, row) => `<code>${data}</code> — ${c.t('erp.invoice.of')} ${row.customerName}`,
 * });
 * ```
 *
 * Registering the same name twice replaces the first: the last import wins, which is what makes an
 * application able to override one of its own without editing the file that declared it.
 */

/** @type {Record<string, (controller: object) => Function>} */
const registry = {};

/**
 * @param {Record<string, (controller: object) => Function>} renderers
 */
export function registerRenderers(renderers) {
    Object.assign(registry, renderers);
}

/**
 * Read by the table controller when a column names a renderer it does not itself provide.
 *
 * @returns {Record<string, (controller: object) => Function>}
 */
export function getRegisteredRenderers() {
    return registry;
}

/**
 * Drops every registration. Only useful between tests — an application registers once, at boot.
 */
export function clearRenderers() {
    for (const key of Object.keys(registry)) {
        delete registry[key];
    }
}

/**
 * Tailwind classes per palette name, light and dark. Kept here rather than composed on the fly
 * because a class name built by string concatenation is invisible to Tailwind's content scanner
 * and gets purged from the stylesheet — the badge then renders unstyled, in production only.
 */
const PALETTES = {
    slate: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:ring-slate-600',
    sky: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-400 dark:ring-sky-800',
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:ring-emerald-800',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:ring-amber-800',
    red: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-900/30 dark:text-red-400 dark:ring-red-800',
    violet: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-400 dark:ring-violet-800',
    accent: 'bg-accent-50 text-accent-700 ring-accent-200 dark:bg-accent-900/30 dark:text-accent-400 dark:ring-accent-800',
    cyan: 'bg-cyan-50 text-cyan-700 ring-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-400 dark:ring-cyan-800',
    orange: 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-900/30 dark:text-orange-400 dark:ring-orange-800',
};

/**
 * The shape almost every business renderer has: an enum value in, a coloured pill out.
 *
 * `labelPath` is where the translated labels sit inside the `translations` value — the same path
 * the server-side `datatable_status_map()` writes them to. `palette` maps each enum case to a
 * colour; a case absent from it falls back to slate rather than rendering nothing.
 *
 * @param {string} labelPath e.g. `datatable.erp_quote_status`
 * @param {Record<string, keyof PALETTES>} palette
 */
export function badge(labelPath, palette) {
    return (c) => (data) => {
        if (!data) {
            return '—';
        }

        const key = String(data);
        const classes = PALETTES[palette[key]] ?? PALETTES.slate;
        const label = c.t(`${labelPath}.${key}`);

        return `<span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ${classes}">${label}</span>`;
    };
}
