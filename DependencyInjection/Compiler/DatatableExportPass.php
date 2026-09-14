<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\DependencyInjection\Compiler;

use Jul6Art\DatatableBundle\Export\DatatableViewExporter;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes {@see DatatableViewExporter} when a preferences store is not bound.
 *
 * ⚠️ **The same reasoning as {@see PreferenceControllerPass}, applied to a second consumer of the
 * same port.** `DatatableViewExporter` reads a user's saved column selection through
 * `DatatablePreferenceStoreInterface` — without an implementation, autowiring it fails the whole
 * build the day an application merely installs `jul6art/dataflow-bundle`, over a service nothing
 * has asked for yet.
 *
 * ⚠️ **Whether `jul6art/dataflow-bundle` itself is installed is answered in the extension, not
 * here** — `class_exists(ReportRunner::class)` decides whether this service is even REGISTERED, per
 * rule 1 of `DatatableExtension`. This pass only ever runs on a container where that was already
 * true, so it asks the one remaining question a compiler pass — not an extension — can answer: does
 * a SERVICE exist yet.
 */
final class DatatableExportPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DatatableViewExporter::class)) {
            return;
        }

        if ($container->has(DatatablePreferenceStoreInterface::class)) {
            return;
        }

        $container->removeDefinition(DatatableViewExporter::class);
    }
}
