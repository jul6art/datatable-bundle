<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Reorder;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a new order of ALL the rows of an entity, in one transaction (2.9).
 *
 * ⚠️ **The list must be exactly the set of rows** — the same identifiers, each once, none missing,
 * none extra. Anything else is a page that went stale (a row added or removed since it loaded, a
 * second tab), and writing it would give two rows the same position or forget one: refused, and
 * nothing is written. The endpoint answers 409 and the table reloads.
 *
 * ⚠️ **Through the ORM, not a bulk `UPDATE`.** One query reads the rows; the flush writes only the
 * ones whose position changed. A DQL update would skip the listeners — the ones that ban a shared
 * cache, the ones that publish on Mercure — and the page would keep the old order until it expires.
 */
final readonly class RowReorderer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param class-string           $entity
     * @param list<int|string>       $ids    the rows, in their new order
     *
     * @return bool false when `$ids` is not exactly the set of rows: nothing was written
     */
    public function reorder(string $entity, string $field, array $ids): bool
    {
        $metadata = $this->entityManager->getClassMetadata($entity);

        if (!$metadata->hasField($field)) {
            throw new \LogicException(\sprintf('%s has no field "%s" to hold its position.', $entity, $field));
        }

        if (1 !== \count($metadata->getIdentifierFieldNames())) {
            throw new \LogicException(\sprintf('%s has a composite identifier: rows cannot be reordered by id.', $entity));
        }

        $rows = [];
        foreach ($this->entityManager->getRepository($entity)->findAll() as $row) {
            $rows[(string) array_values($metadata->getIdentifierValues($row))[0]] = $row;
        }

        $wanted = array_map(strval(...), $ids);

        if (\count($wanted) !== \count($rows) || \count(array_unique($wanted)) !== \count($wanted) || [] !== array_diff($wanted, array_map(strval(...), array_keys($rows)))) {
            return false;
        }

        $this->entityManager->wrapInTransaction(function () use ($wanted, $rows, $metadata, $field): void {
            foreach ($wanted as $position => $id) {
                $metadata->setFieldValue($rows[$id], $field, $position);
            }

            $this->entityManager->flush();
        });

        return true;
    }
}
