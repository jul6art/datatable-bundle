<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Export;

use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceInterpreter;
use Jul6Art\DataflowBundle\Report\Spec\FilterOperator;
use Jul6Art\DataflowBundle\Report\Spec\ReportColumn;
use Jul6Art\DataflowBundle\Report\Spec\ReportFilter;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpec;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns a config provider, a user's decoded preferences, and the current request's query string
 * into a {@see ReportSpec} — the whole translation {@see DatatableViewExporter} needs, kept
 * SEPARATE from it so this half is unit-testable without a real `ReportRunner` or a database.
 *
 * ⚠️ `ReportRunner` is `final`, and this bundle's own conventions do not mock what a project would
 * never mock either — {@see \Jul6Art\DataflowBundle\Tests\Functional\ReportRunnerTest} in the
 * bundle that owns it runs every assertion against a real SQLite database, on principle. Isolating
 * the PURE translation here is what lets THIS bundle prove the property that is actually its own —
 * "the preferences and the request produce the right spec" — without duplicating that
 * infrastructure just to hold it.
 */
final readonly class DatatableReportSpecBuilder
{
    public function __construct(
        private DatatablePreferenceInterpreter $interpreter,
    ) {
    }

    /**
     * @throws \LogicException when $provider->rootEntity() is null
     */
    public function build(
        AbstractDataTableConfigProvider $provider,
        ?string $storedPreferences,
        Request $request,
    ): ReportSpec {
        $rootEntity = $provider->rootEntity();

        if (null === $rootEntity) {
            throw new \LogicException(\sprintf(
                '%s is not exportable: rootEntity() returns null. Override it to opt in.',
                $provider::class,
            ));
        }

        return new ReportSpec(
            $rootEntity,
            $this->columns($provider, $storedPreferences),
            $this->filters($provider, $request),
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(AbstractDataTableConfigProvider $provider, ?string $storedPreferences): array
    {
        $declared = [];
        foreach ($provider->getColumns() as $column) {
            $key = $column['data'] ?? null;
            if (\is_string($key) && '' !== $key) {
                $declared[$key] = $column;
            }
        }

        $order = $this->interpreter->decode($storedPreferences)['columns'];

        // ⚠️ No saved preferences is not "export nothing": it is the state of every user before
        // their first visit to the column picker, and the table itself shows every non-hidden
        // column in that case — the export must match what the SCREEN shows by default.
        if ([] === $order) {
            foreach ($declared as $key => $column) {
                if (true !== ($column['hidden'] ?? false)) {
                    $order[] = ['key' => $key, 'visible' => true];
                }
            }
        }

        $columns = [];
        foreach ($order as $entry) {
            $key = $entry['key'];

            if (!$entry['visible'] || !isset($declared[$key])) {
                continue;
            }

            $column = $declared[$key];

            // ⚠️ A computed column has no equivalent in the report engine — a chip list built from
            // a collection, a badge derived in the API resource, a value with no Doctrine path at
            // all. `rootEntity()` opts a TABLE in; a project still has to opt each such column OUT,
            // because only it knows which of its own renderings are not a real field. Dropped the
            // same way an undeclared preference is: the export degrades, it does not fail whole.
            if (false === ($column['reportable'] ?? true)) {
                continue;
            }

            $title = $column['title'] ?? $key;
            $columns[] = new ReportColumn($this->reportPath($key, $column), \is_string($title) ? $title : $key);
        }

        return $columns;
    }

    /**
     * ⚠️ An `iri` column shows a TO-ONE RELATION through a resolved label — `resolveField` (default
     * `name`, the same fallback `datatable_controller.js` applies) names which of the related
     * entity's own fields the table actually displays, and it is exactly the leaf the report engine
     * needs too. Without this translation, a bare relation key such as `company` reaches
     * `ReportRunner` as a path with no scalar to select, and `FieldCatalog` refuses it outright:
     * "is not a reportable field" — found wiring the very first `iri` column into an export (lot
     * 2.8's own screen verification, not a synthetic test).
     *
     * @param array<string, mixed> $column
     */
    private function reportPath(string $key, array $column): string
    {
        if ('iri' !== ($column['render'] ?? null)) {
            return $key;
        }

        $resolveField = $column['resolveField'] ?? null;

        return \sprintf('%s.%s', $key, \is_string($resolveField) && '' !== $resolveField ? $resolveField : 'name');
    }

    /**
     * @return list<ReportFilter>
     */
    private function filters(AbstractDataTableConfigProvider $provider, Request $request): array
    {
        $filters = [];

        foreach ($provider->getFilters() as $declared) {
            $filter = $this->filterFromRequest($declared, $request);

            if (null !== $filter) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $declared
     */
    private function filterFromRequest(array $declared, Request $request): ?ReportFilter
    {
        $column = $declared['column'] ?? null;
        $param = $declared['param'] ?? null;
        $type = $declared['type'] ?? null;

        if (!\is_string($column) || '' === $column || !\is_string($param) || '' === $param) {
            return null;
        }

        return match ($type) {
            'daterange' => $this->dateRangeFilter($column, $param, $request),
            'static', 'api' => $this->equalityFilter($column, $param, $request),
            default => null,
        };
    }

    /**
     * API Platform's `DateFilter` convention: `?param[after]=` / `?param[before]=`.
     */
    private function dateRangeFilter(string $column, string $param, Request $request): ?ReportFilter
    {
        // ⚠️ `$request->query->all($param)` — the ONE-argument form — THROWS `BadRequestException`
        // when the parameter exists but is not an array, which a crafted `?issuedAt=x` is. Reading
        // the whole bag and indexing it by hand is what lets a malformed value be dropped, the same
        // rule `ReportSpecInterpreter` applies to every other malformed field of a saved report.
        $range = $request->query->all()[$param] ?? null;
        $after = \is_array($range) && \is_string($range['after'] ?? null) ? $range['after'] : null;
        $before = \is_array($range) && \is_string($range['before'] ?? null) ? $range['before'] : null;

        return match (true) {
            null !== $after && null !== $before => new ReportFilter($column, FilterOperator::Between, $after, $before),
            null !== $after => new ReportFilter($column, FilterOperator::GreaterThanOrEqual, $after),
            null !== $before => new ReportFilter($column, FilterOperator::LessThanOrEqual, $before),
            default => null,
        };
    }

    /**
     * `static` and `api` both narrow to one or several values by identity — a select's option
     * value, an autocomplete's chosen id.
     */
    private function equalityFilter(string $column, string $param, Request $request): ?ReportFilter
    {
        // ⚠️ Same reason as dateRangeFilter(): the one-argument `all($param)` throws on a value
        // that is not an array, which the single-value case below is.
        $raw = $request->query->all()[$param] ?? null;

        if (\is_array($raw) && [] !== $raw && \array_is_list($raw)) {
            $values = \array_values(\array_filter($raw, \is_string(...)));

            return [] === $values ? null : new ReportFilter($column, FilterOperator::In, $values);
        }

        return \is_string($raw) && '' !== $raw
            ? new ReportFilter($column, FilterOperator::Equals, $raw)
            : null;
    }
}
