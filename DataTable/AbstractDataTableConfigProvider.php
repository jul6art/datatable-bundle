<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DataTable;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The declarative half of a server-driven datatable: what the columns are, what can be filtered,
 * what each row can do.
 *
 * ```php
 * final class UserDataTableConfigProvider extends AbstractDataTableConfigProvider
 * {
 *     public function getColumns(): array
 *     {
 *         return [
 *             $this->column('id', 'datatable.col.id', responsivePriority: 1),
 *             $this->column('email', 'user.field.email', 'user'),
 *             $this->readOnlyColumn('roles', 'user.field.roles', 'user', render: 'badges'),
 *         ];
 *     }
 *
 *     public function getFilters(): array
 *     {
 *         return [$this->dateRangeFilter('createdAt', 'createdAt', 'datatable.filter.created')];
 *     }
 * }
 * ```
 *
 * ## What consumes this
 *
 * Nothing here renders or queries anything. Each helper returns a plain array, serialised into the
 * `data-datatable-*-value` attributes read by the `datatable` Stimulus controller this bundle
 * ships. The shape follows DataTables\' column and search conventions, and the `param` values
 * follow API Platform\'s filter conventions (`?param[after]=` / `?param[before]=` for a date
 * range). The one thing the bundle cannot provide is the endpoint itself: an `#[ApiResource]`
 * exposing a `GetCollection`, with the filters the `param` names refer to.
 *
 * ## Why the helpers rather than literal arrays
 *
 * Every label goes through `t()`. Writing the arrays by hand is what produces a table where one
 * column header is translated and the next is an untranslated key, which no test catches because
 * a datatable configuration has no expected output — only a rendered page a human has to look at.
 *
 * `titleDomain` defaults to `messages` and is worth passing: a project splitting its catalogues by
 * functional domain gets a key resolved against the wrong domain rendered as the raw key.
 *
 * ## Declaring wide, showing narrow
 *
 * Since per-user preferences exist, a column the reader can hide costs nothing to the reader who
 * does not want it. The arbitration changed: the question is no longer "does this column deserve
 * the width?" but "could anyone want to see it?". Pass `hidden: true` for the second kind — the
 * column is in the picker, absent from the first paint, and one tick away.
 *
 * What does NOT change is the SQL: a hidden column is not rendered, but it is still serialised.
 * `hidden: true` is a display default, never a reason to add a field to a `read` group.
 */
abstract class AbstractDataTableConfigProvider
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ── Columns ────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    abstract public function getColumns(): array;

    // ── Filters ────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function getFilters(): array
    {
        return [];
    }

    // ── Column helpers ─────────────────────────────────────────

    /**
     * Sortable column.
     *
     * `$hidden` declares a column the table OFFERS without SHOWING: it is absent on first open and
     * the user ticks it in the column picker. It is what makes a wide table possible — see the note
     * on the class above — and it only means anything for a table that opted into per-user
     * preferences. Without them there is no picker, so a hidden column would be unreachable; the
     * flag is then ignored and the column shows, which is the lesser surprise.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function column(string $data, string $titleKey, string $titleDomain = 'messages', ?string $sortField = null, ?string $render = null, int $responsivePriority = 5, array $extra = [], bool $hidden = false): array
    {
        return array_merge([
            'data' => $data,
            'title' => $this->t($titleKey, $titleDomain),
            'responsivePriority' => $responsivePriority,
            'sortField' => $sortField ?? $data,
        ], $render ? ['render' => $render] : [], $hidden ? ['hidden' => true] : [], $extra);
    }

    /**
     * Non-sortable column.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function readOnlyColumn(string $data, string $titleKey, string $titleDomain = 'messages', ?string $render = null, int $responsivePriority = 5, array $extra = [], bool $hidden = false): array
    {
        return array_merge([
            'data' => $data,
            'title' => $this->t($titleKey, $titleDomain),
            'responsivePriority' => $responsivePriority,
            'orderable' => false,
        ], $render ? ['render' => $render] : [], $hidden ? ['hidden' => true] : [], $extra);
    }

    // ── Filter helpers ─────────────────────────────────────────

    /**
     * Static select filter (predefined options).
     *
     * @param list<array{value: string, label: string}> $options
     *
     * @return array<string, mixed>
     */
    protected function staticFilter(string $column, string $param, string $placeholderKey, array $options, string $placeholderDomain = 'messages'): array
    {
        return [
            'column' => $column,
            'type' => 'static',
            'param' => $param,
            'placeholder' => $this->t($placeholderKey, $placeholderDomain),
            'options' => $options,
        ];
    }

    /**
     * Date-range filter — produces 2 `<input type="date">` inputs in
     * the column header that bind to `<param>[after]` and `<param>[before]`
     * query params (API Platform `DateFilter` convention).
     *
     * ⚠️ `$granularity` is not cosmetic — it decides whether the front-end converts the civil date
     * the user picked into a UTC instant, and getting it wrong shifts results by one day for every
     * user whose browser is not on UTC:
     *   - `'date'` (default) — for a `date_immutable` column. The raw civil date `YYYY-MM-DD` is
     *     sent, with no conversion. Converting here is the bug: midnight local becomes the
     *     previous day in UTC, and an invoice dated the 1st stops matching a range starting the
     *     1st.
     *   - `'datetime'` — for a `datetime` column such as `createdAt`. The civil date is converted
     *     to a UTC instant, so "today" means the user's today and not UTC's.
     *
     * @return array<string, mixed>
     */
    protected function dateRangeFilter(string $column, string $param, string $placeholderKey, string $placeholderDomain = 'messages', string $granularity = 'date'): array
    {
        return [
            'column' => $column,
            'type' => 'daterange',
            'param' => $param,
            'placeholder' => $this->t($placeholderKey, $placeholderDomain),
            'granularity' => $granularity,
        ];
    }

    /**
     * API-driven select filter (AJAX search).
     *
     * `searchKey` decouples the search query parameter from the display key.
     * Default falls back to `textKey` (legacy behavior). Use `'search'` when
     * targeting an `OrSearchFilter` exposed under `?search=` on the resource
     * (e.g. Contact/User which display `fullName` but search via `firstName +
     * lastName + email`).
     *
     * `dependsOn` / `dependsParam` make this an autocomplete **scoped** to the
     * live value of another filter (`dependsOn` = that filter's `param`),
     * appended to the autocomplete request under `dependsParam` — e.g. the
     * task filter scoped to the selected project (`dependsOn: 'project'`,
     * `dependsParam: 'project'`). No extra SQL: the param filters the existing
     * autocomplete query. Changing the parent clears this filter. Cf.
     * `docs/corrections/2026-06-14-3.md` P1.
     *
     * @return array<string, mixed>
     */
    protected function apiFilter(string $column, string $param, string $placeholderKey, string $url, string $textKey = 'name', string $idKey = 'id', ?string $searchKey = null, string $placeholderDomain = 'messages', ?string $dependsOn = null, ?string $dependsParam = null): array
    {
        return [
            'column' => $column,
            'type' => 'api',
            'param' => $param,
            'placeholder' => $this->t($placeholderKey, $placeholderDomain),
            'url' => $url,
            'textKey' => $textKey,
            'idKey' => $idKey,
            'searchKey' => $searchKey ?? $textKey,
            'dependsOn' => $dependsOn,
            'dependsParam' => $dependsParam,
        ];
    }

    // ── Action helpers ─────────────────────────────────────────

    /**
     * Link action (GET).
     *
     * @return array<string, mixed>
     */
    protected function linkAction(string $type, string $route, string $icon, string $labelKey, string $labelDomain = 'messages'): array
    {
        return [
            'type' => $type,
            'route' => $route,
            'icon' => $icon,
            'label' => $this->t($labelKey, $labelDomain),
        ];
    }

    /**
     * POST action with confirmation modal.
     *
     * @return array<string, mixed>
     */
    protected function postAction(string $type, string $route, string $icon, string $labelKey, string $variant = 'danger', string $labelDomain = 'messages'): array
    {
        return [
            'type' => $type,
            'route' => $route,
            'icon' => $icon,
            'label' => $this->t($labelKey, $labelDomain),
            'method' => 'POST',
            'variant' => $variant,
        ];
    }

    /**
     * Soft-delete action with single + bulk variants. Mirrors the
     * pattern used across the ERP module : single button on each row,
     * bulk action exposed in the multi-select dropdown.
     *
     * Cf. the project that this was extracted from for the incident history.
     *
     * @param array<string, mixed> $extras merged into the action entry
     *                                     (typically `condition`)
     *
     * @return array<string, mixed>
     */
    protected function bulkDeleteAction(
        string $singleRoute,
        string $bulkRoute,
        array $extras = [],
        string $labelDomain = 'messages',
    ): array {
        return array_merge(
            $this->postAction('delete', $singleRoute, 'trash', 'action.delete', 'danger', $labelDomain),
            [
                'class' => 'text-red-500 hover:text-red-700',
                'bulk' => true,
                'bulkRoute' => $bulkRoute,
                'bulkLabel' => $this->t('action.delete', $labelDomain),
            ],
            $extras,
        );
    }

    // ── Translation ────────────────────────────────────────────

    /**
     * Translates a label. Protected rather than private so a subclass can translate something the
     * helpers above do not cover — a `render` callback\'s labels, typically.
     *
     * @param array<string, string> $params
     */
    protected function t(string $key, string $domain = 'messages', array $params = []): string
    {
        return $this->translator->trans($key, $params, $domain);
    }
}
