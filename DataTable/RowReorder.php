<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DataTable;

/**
 * A table whose rows the user can reorder by hand (2.9): the street of a portfolio, the steps of a
 * checklist. A config provider declares it in {@see AbstractDataTableConfigProvider::rowReorder()}.
 *
 * - `key` names the table in the endpoint's URL (`POST …/{key}/reorder`) — the same vocabulary as
 *   the preferences key: `[a-z0-9][a-z0-9_.-]{0,63}`.
 * - `field` is the entity's integer position, written 0, 1, 2… in the new order.
 * - `permission` is what the endpoint asks `isGranted()` before writing anything. Reordering is a
 *   write of every row: a user who may read the list is not, by that, one who may reorder it.
 */
final readonly class RowReorder
{
    public function __construct(
        public string $key,
        public string $field,
        public string $permission,
    ) {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\-]{0,63}$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A row reorder key must match [a-z0-9][a-z0-9_.-]{0,63}, "%s" given.', $key));
        }
    }
}
