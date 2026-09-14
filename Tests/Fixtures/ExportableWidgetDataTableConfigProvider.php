<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures;

use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;

/**
 * The exportable twin of {@see WidgetDataTableConfigProvider} — a separate fixture rather than a
 * subclass, since that one is `final`. Every OTHER test of the plain fixture must keep proving that
 * a provider is not exportable BY DEFAULT, so the two must stay two classes.
 */
final class ExportableWidgetDataTableConfigProvider extends AbstractDataTableConfigProvider
{
    /**
     * ⚠️ Not a Doctrine entity, on purpose: {@see \Jul6Art\DataflowBundle\Report\Spec\ReportSpec}
     * does not verify its root is one — only
     * {@see \Jul6Art\DataflowBundle\Report\Spec\ReportSpecInterpreter} does, for a payload from the
     * outside world — so proving the TRANSLATION this bundle owns needs no real fixture entity, a
     * `class-string` PHPStan can actually resolve is enough.
     */
    #[\Override]
    public function rootEntity(): string
    {
        return \stdClass::class;
    }

    /**
     * ⚠️ The same columns as {@see WidgetDataTableConfigProvider}, on purpose: the two fixtures'
     * tests read as a matched pair — the plain one proves export is refused, this one proves what
     * it does once opted in — and a divergent column list would make that comparison meaningless.
     *
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getColumns(): array
    {
        return [
            $this->column('id', 'datatable.col.id', responsivePriority: 1),
            $this->column('name', 'widget.field.name', 'widget'),
            $this->column('reference', 'widget.field.reference', 'widget', sortField: 'sortableReference'),
            $this->readOnlyColumn('tags', 'widget.field.tags', 'widget', render: 'badges'),
            $this->column('notes', 'widget.field.notes', 'widget', hidden: true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            $this->dateRangeFilter('issuedAt', 'issuedAt', 'widget.filter.issued', 'widget'),
            $this->apiFilter('category', 'category', 'widget.filter.category', '/api/categories'),
        ];
    }
}
