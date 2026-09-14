<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\DependencyInjection\Compiler;

use Jul6Art\DatatableBundle\DependencyInjection\Compiler\DatatableExportPass;
use Jul6Art\DatatableBundle\Export\DatatableViewExporter;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The pass in isolation, against a bare {@see ContainerBuilder} — not a booted kernel.
 *
 * ⚠️ **Deliberately not a functional test.** `DatatableViewExporter` also needs `ReportRunner`,
 * which itself needs an `EntityManagerInterface` and `jul6art/acl-bundle`'s
 * `PermissionDecisionService` — wiring all three for real, inside THIS bundle's own test kernel,
 * would prove infrastructure this bundle does not own and does not need to. What this bundle IS
 * responsible for is the decision `DatatableExportPass` makes, and a bare `ContainerBuilder` with a
 * stand-in definition proves exactly that, without a database.
 */
#[CoversClass(DatatableExportPass::class)]
final class DatatableExportPassTest extends TestCase
{
    public function testTheExporterIsRemovedWithoutAPreferencesStore(): void
    {
        $container = $this->container();

        new DatatableExportPass()->process($container);

        self::assertFalse($container->hasDefinition(DatatableViewExporter::class));
    }

    public function testTheExporterSurvivesWithAPreferencesStore(): void
    {
        $container = $this->container();
        $container->register(DatatablePreferenceStoreInterface::class, DatatablePreferenceStoreInterface::class);

        new DatatableExportPass()->process($container);

        self::assertTrue($container->hasDefinition(DatatableViewExporter::class));
    }

    /**
     * ⚠️ An alias counts as much as a definition — the ordinary Symfony pattern for binding a port
     * to a concrete implementation, exactly how a real project wires its own store.
     */
    public function testAnAliasedStoreAlsoCountsAsBound(): void
    {
        $container = $this->container();
        $container->register('app.my_store', 'stdClass');
        $container->setAlias(DatatablePreferenceStoreInterface::class, 'app.my_store');

        new DatatableExportPass()->process($container);

        self::assertTrue($container->hasDefinition(DatatableViewExporter::class));
    }

    public function testNothingHappensWhenTheExporterWasNeverRegisteredAtAll(): void
    {
        // The extension itself did not load `export.yaml` — `jul6art/dataflow-bundle` is absent.
        // The pass must not fail merely because there is nothing for it to do.
        $container = new ContainerBuilder();

        new DatatableExportPass()->process($container);

        self::assertFalse($container->hasDefinition(DatatableViewExporter::class));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(DatatableViewExporter::class, new Definition(DatatableViewExporter::class));

        return $container;
    }
}
