<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Fixtures;

use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;

/**
 * What a project writes: one subclass per listing, declaring columns and filters through the
 * helpers so every label goes through the translator.
 */
final class WidgetDataTableConfigProvider extends AbstractDataTableConfigProvider
{
    /**
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
            // Offerte sans être montrée : dans le sélecteur, absente du premier rendu.
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
            $this->dateRangeFilter('createdAt', 'createdAt', 'widget.filter.created', 'widget', granularity: 'datetime'),
            $this->apiFilter('category', 'category', 'widget.filter.category', '/api/categories'),
            $this->apiFilter('task', 'task', 'widget.filter.task', '/api/tasks', searchKey: 'search', dependsOn: 'category', dependsParam: 'category'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteAction(): array
    {
        return $this->bulkDeleteAction('widget_delete', 'widget_bulk_delete');
    }
}
