<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection\Compiler;

use Jul6Art\DatatableBundle\Controller\DatatableReorderController;
use Jul6Art\DatatableBundle\Reorder\RowReorderer;
use Jul6Art\DatatableBundle\Reorder\RowReorderRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the row reorder endpoint (2.9) when the services it writes through are missing: an
 * entity manager (DoctrineBundle) or the authorization checker (SecurityBundle). The CLASSES may be
 * in the tree as dependencies of dependencies while the SERVICES were never registered, and the
 * extension cannot tell — it runs before the other bundles have configured anything.
 */
final class ReorderControllerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DatatableReorderController::class)) {
            return;
        }

        if ($container->has('doctrine.orm.entity_manager') && $container->has('security.authorization_checker')) {
            return;
        }

        $container->removeDefinition(DatatableReorderController::class);
        $container->removeDefinition(RowReorderer::class);
        $container->removeDefinition(RowReorderRegistry::class);
    }
}
