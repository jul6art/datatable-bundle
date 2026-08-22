<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DataTable;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a tenant-scoped datatable configuration into a cross-tenant one, by inserting the tenant
 * column and its filter.
 *
 * ```php
 * $columns = $admin->decorateColumns($provider->getColumns());
 * $filters = [...$provider->getFilters(), $admin->tenantFilter()];
 * ```
 *
 * The point is that a super-admin listing reuses the *same* provider a tenant user sees, rather
 * than a parallel copy of it. A duplicated configuration is the kind that drifts one column at a
 * time, and nobody notices until an admin page is missing a field that shipped months ago.
 *
 * The column lands **second**, right after an `id` column, or first when there is none. That
 * ordering is a deliberate convention rather than an accident: an admin sweeping several modules
 * reads the same two leading columns everywhere.
 *
 * ## What has to be configured
 *
 * The endpoint the filter searches and the label it shows both belong to the application — a
 * "tenant" is an `Organization` here, a `Workspace` or an `Account` elsewhere:
 *
 * ```yaml
 * datatable:
 *     tenant:
 *         endpoint: /api/organizations
 *         label_key: datatable.col.organization
 * ```
 *
 * The endpoint must expose a search filter under `?search=` for the autocomplete to work, and
 * return `id` and `name` fields. That is a real coupling and it is why these are configuration
 * rather than constants.
 */
final readonly class AdminDataTableConfig
{
    public function __construct(
        private TranslatorInterface $translator,
        private string $tenantEndpoint,
        private string $tenantLabelKey,
        private string $tenantLabelDomain = 'messages',
    ) {
    }

    /**
     * @param list<array<string, mixed>> $columns
     *
     * @return list<array<string, mixed>>
     */
    public function decorateColumns(array $columns): array
    {
        $tenantColumn = $this->tenantColumn();

        if ([] !== $columns && ($columns[0]['data'] ?? null) === 'id') {
            return [$columns[0], $tenantColumn, ...\array_slice($columns, 1)];
        }

        return [$tenantColumn, ...$columns];
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantColumn(): array
    {
        return [
            'data' => 'organization',
            'title' => $this->label(),
            'orderable' => false,
            // Le champ porte une IRI : le front la résout et affiche `name`. Trier dessus
            // trierait sur l'URL, ce qui n'a pas de sens pour un humain — d'où `orderable: false`.
            'render' => 'iri',
            'resolveField' => 'name',
            'responsivePriority' => 1,
        ];
    }

    /**
     * An autocomplete filter, so a super admin can scope a cross-tenant table to one tenant.
     *
     * @return array<string, mixed>
     */
    public function tenantFilter(): array
    {
        return [
            'column' => 'organization',
            'type' => 'api',
            'param' => 'organization',
            'placeholder' => $this->label(),
            'url' => $this->tenantEndpoint,
            'textKey' => 'name',
            'idKey' => 'id',
            'searchKey' => 'search',
        ];
    }

    private function label(): string
    {
        return $this->translator->trans($this->tenantLabelKey, [], $this->tenantLabelDomain);
    }
}
