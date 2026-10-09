<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Reorder;

use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;
use Jul6Art\DatatableBundle\DataTable\RowReorder;

/**
 * The tables the application declared reorderable, by key: every config provider whose
 * `rowReorder()` answers (2.9). The providers come tagged — the bundle autoconfigures every
 * subclass of {@see AbstractDataTableConfigProvider}.
 */
final readonly class RowReorderRegistry
{
    /**
     * @param iterable<AbstractDataTableConfigProvider> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array{reorder: RowReorder, entity: class-string}|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->providers as $provider) {
            $reorder = $provider->rowReorder();

            if (!$reorder instanceof RowReorder || $reorder->key !== $key) {
                continue;
            }

            $entity = $provider->rootEntity() ?? throw new \LogicException(\sprintf('%s declares a row reorder but no rootEntity(): the endpoint cannot tell which rows to write.', $provider::class));

            return ['reorder' => $reorder, 'entity' => $entity];
        }

        return null;
    }
}
