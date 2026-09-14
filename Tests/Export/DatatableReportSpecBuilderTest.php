<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Export;

use Jul6Art\DataflowBundle\Report\Spec\ReportColumn;
use Jul6Art\DataflowBundle\Report\Spec\ReportFilter;
use Jul6Art\DataflowBundle\Report\Spec\ReportSpec;
use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;
use Jul6Art\DatatableBundle\Export\DatatableReportSpecBuilder;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceInterpreter;
use Jul6Art\DatatableBundle\Tests\Fixtures\ExportableWidgetDataTableConfigProvider;
use Jul6Art\DatatableBundle\Tests\Fixtures\WidgetDataTableConfigProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The pure translation lot 2.8 exists for — no database, no `ReportRunner`, on purpose. See the
 * class docblock this tests.
 */
#[CoversClass(DatatableReportSpecBuilder::class)]
final class DatatableReportSpecBuilderTest extends TestCase
{
    public function testAProviderThatHasNotOptedInRefusesToBuild(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/rootEntity/');

        $this->builder()->build(new WidgetDataTableConfigProvider($this->translator()), null, new Request());
    }

    public function testTheRootEntityIsTheProvidersOwn(): void
    {
        $spec = $this->builder()->build($this->provider(), null, new Request());

        self::assertSame(\stdClass::class, $spec->rootEntity);
    }

    /**
     * ⚠️ The property that matters most: no preferences saved yet is the state of EVERY user
     * before their first visit to the picker, and the export must match what the table shows by
     * default — every declared column, `notes` excluded because it declares `hidden: true`.
     */
    public function testWithNoSavedPreferencesEveryNonHiddenColumnExportsInDeclarationOrder(): void
    {
        $spec = $this->builder()->build($this->provider(), null, new Request());

        self::assertSame(['id', 'name', 'reference', 'tags'], self::paths($spec));
    }

    public function testSavedPreferencesDecideVisibilityAndOrder(): void
    {
        $preferences = json_encode([
            'columns' => [
                ['key' => 'reference', 'visible' => true],
                ['key' => 'name', 'visible' => false],
                ['key' => 'id', 'visible' => true],
            ],
        ], \JSON_THROW_ON_ERROR);

        $spec = $this->builder()->build($this->provider(), $preferences, new Request());

        // `name` is hidden by the SAVED preference, and the order is the SAVED one — neither
        // matches declaration order, which is exactly what would pass if this class fell back to
        // the declared list instead of reading the preference.
        self::assertSame(['reference', 'id'], self::paths($spec));
    }

    /**
     * ⚠️ A key the preferences name but the provider no longer declares — a column removed since
     * the user last saved — must not reach the report as a phantom path the catalogue would refuse.
     */
    public function testAPreferenceNamingAnUndeclaredColumnIsDropped(): void
    {
        $preferences = json_encode([
            'columns' => [
                ['key' => 'id', 'visible' => true],
                ['key' => 'no-longer-declared', 'visible' => true],
            ],
        ], \JSON_THROW_ON_ERROR);

        $spec = $this->builder()->build($this->provider(), $preferences, new Request());

        self::assertSame(['id'], self::paths($spec));
    }

    /**
     * ⚠️ Not the bare column key: `AbstractDataTableConfigProvider::column()` always sets a
     * `title`, already run through the translator — the fallback to the key itself only matters
     * for a hand-built column array that skipped the helpers, which the stub translator here makes
     * visible by echoing the KEY BACK, unchanged.
     */
    public function testTheLabelIsTheProvidersTranslatedTitle(): void
    {
        $spec = $this->builder()->build($this->provider(), null, new Request());

        self::assertSame('datatable.col.id', $spec->columns[0]->label);
    }

    /**
     * ⚠️ API Platform's own convention: `?param[after]=` / `?param[before]=`. Both present is a
     * range; either alone is a one-sided bound — the three must be told apart, not collapsed into
     * one operator that silently ignores the missing side.
     */
    public function testADateRangeWithBothBoundsBecomesBetween(): void
    {
        $request = new Request(['issuedAt' => ['after' => '2026-01-01', 'before' => '2026-01-31']]);

        $spec = $this->builder()->build($this->provider(), null, $request);

        $filter = self::filterFor($spec, 'issuedAt');
        self::assertSame('between', $filter->operator->value);
        self::assertSame('2026-01-01', $filter->value);
        self::assertSame('2026-01-31', $filter->secondValue);
    }

    public function testADateRangeWithOnlyAnAfterBoundBecomesGreaterThanOrEqual(): void
    {
        $request = new Request(['issuedAt' => ['after' => '2026-01-01']]);

        $filter = self::filterFor($this->builder()->build($this->provider(), null, $request), 'issuedAt');

        self::assertSame('gte', $filter->operator->value);
        self::assertSame('2026-01-01', $filter->value);
    }

    public function testADateRangeWithOnlyABeforeBoundBecomesLessThanOrEqual(): void
    {
        $request = new Request(['issuedAt' => ['before' => '2026-01-31']]);

        $filter = self::filterFor($this->builder()->build($this->provider(), null, $request), 'issuedAt');

        self::assertSame('lte', $filter->operator->value);
        self::assertSame('2026-01-31', $filter->value);
    }

    public function testADateRangeWithNeitherBoundProducesNoFilterAtAll(): void
    {
        $spec = $this->builder()->build($this->provider(), null, new Request());

        self::assertNull(self::tryFilterFor($spec, 'issuedAt'));
    }

    /**
     * ⚠️ An `api` filter narrows by identity — an autocomplete's chosen id — the same as a
     * `static` select. One request shape, two declared types, one translation.
     */
    public function testAnApiFilterWithASingleValueBecomesEquals(): void
    {
        $request = new Request(['category' => 'electronics']);

        $filter = self::filterFor($this->builder()->build($this->provider(), null, $request), 'category');

        self::assertSame('eq', $filter->operator->value);
        self::assertSame('electronics', $filter->value);
    }

    /**
     * ⚠️ `(array) "a,b"` is the shape a naive cast would produce and it is wrong: a real multi-value
     * query string (`?category[]=a&category[]=b`) parses to a LIST, which is what this asserts —
     * not a comma-joined single value nobody sent.
     */
    public function testAMultiValuedFilterBecomesIn(): void
    {
        $request = new Request(['category' => ['electronics', 'garden']]);

        $filter = self::filterFor($this->builder()->build($this->provider(), null, $request), 'category');

        self::assertSame('in', $filter->operator->value);
        self::assertSame(['electronics', 'garden'], $filter->value);
    }

    public function testAFilterWithNoMatchingQueryParameterProducesNothing(): void
    {
        $spec = $this->builder()->build($this->provider(), null, new Request());

        self::assertSame([], $spec->filters);
    }

    /**
     * ⚠️ An `iri` column shows a relation through a RESOLVED LABEL — the report engine has no such
     * rendering step, so the bare relation key (`company`) is not a scalar it can select.
     * `resolveField` is the leaf the datatable's own JS already reads to display it; the path built
     * for the report has to be the same one, or `FieldCatalog` refuses it outright at run time.
     * Found wiring the very first `iri` column into a real export (lot 2.8's own screen
     * verification), not written from a hypothesis.
     */
    public function testAnIriColumnExportsTheResolvedFieldNotTheBareRelation(): void
    {
        $provider = new class($this->translator()) extends AbstractDataTableConfigProvider {
            #[\Override]
            public function rootEntity(): string
            {
                return \stdClass::class;
            }

            #[\Override]
            public function getColumns(): array
            {
                return [
                    $this->readOnlyColumn('company', 'widget.field.company', 'widget', render: 'iri', extra: ['resolveField' => 'legalName']),
                ];
            }

            #[\Override]
            public function getFilters(): array
            {
                return [];
            }
        };

        $spec = $this->builder()->build($provider, null, new Request());

        self::assertSame(['company.legalName'], self::paths($spec));
    }

    /**
     * ⚠️ `resolveField` is optional — `datatable_controller.js` falls back to `name` when a column
     * omits it (`col.resolveField || 'name'`), and the export has to fall back to the SAME field or
     * the two would silently disagree on what an `iri` column even means.
     */
    public function testAnIriColumnWithNoResolveFieldDefaultsToName(): void
    {
        $provider = new class($this->translator()) extends AbstractDataTableConfigProvider {
            #[\Override]
            public function rootEntity(): string
            {
                return \stdClass::class;
            }

            #[\Override]
            public function getColumns(): array
            {
                return [
                    $this->readOnlyColumn('organization', 'widget.field.organization', 'widget', render: 'iri'),
                ];
            }

            #[\Override]
            public function getFilters(): array
            {
                return [];
            }
        };

        $spec = $this->builder()->build($provider, null, new Request());

        self::assertSame(['organization.name'], self::paths($spec));
    }

    private function builder(): DatatableReportSpecBuilder
    {
        return new DatatableReportSpecBuilder(new DatatablePreferenceInterpreter());
    }

    private function provider(): ExportableWidgetDataTableConfigProvider
    {
        return new ExportableWidgetDataTableConfigProvider($this->translator());
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id ?? '';
            }

            #[\Override]
            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function paths(ReportSpec $spec): array
    {
        return array_map(static fn (ReportColumn $column): string => $column->path, $spec->columns);
    }

    private static function filterFor(ReportSpec $spec, string $path): ReportFilter
    {
        $filter = self::tryFilterFor($spec, $path);
        self::assertNotNull($filter, \sprintf('No filter was built for "%s".', $path));

        return $filter;
    }

    private static function tryFilterFor(ReportSpec $spec, string $path): ?ReportFilter
    {
        foreach ($spec->filters as $filter) {
            if ($filter->path === $path) {
                return $filter;
            }
        }

        return null;
    }
}
