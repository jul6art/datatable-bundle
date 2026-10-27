<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A short list opens without a search field.
 *
 * Select2 puts a search field above every list it opens, whatever its length: the page-length list
 * of each table offered one to filter four numbers (devingame, 2026-10-27). The `ui--select2`
 * controller now takes Select2's `minimumResultsForSearch`, and the two short lists the table draws
 * set it — never a field for the lengths, one from eight bulk actions on.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that the
 * decisions are still made in the files.
 */
#[CoversNothing]
final class Select2SearchFieldSourceTest extends TestCase
{
    /** The threshold is a value of the controller, Select2's default (0, always a field) unless set. */
    public function testTheControllerTakesTheThreshold(): void
    {
        self::assertMatchesRegularExpression(
            '/minimumResultsForSearch:\s*\{\s*type:\s*Number,\s*default:\s*0\s*\}/',
            self::source('select2_controller.js'),
        );
    }

    /**
     * An autocomplete is a search: its first results are not there yet when it opens, and a threshold
     * would count none and hide the only field it has.
     */
    public function testAnAutocompleteKeepsItsField(): void
    {
        self::assertStringContainsString(
            'minimumResultsForSearch: this.urlValue ? 0 : this.minimumResultsForSearchValue,',
            self::source('select2_controller.js'),
            'Avec une `url`, le champ de recherche doit rester.',
        );
    }

    public function testTheTableSetsItOnItsShortLists(): void
    {
        $table = self::source('datatable_controller.js');

        self::assertStringContainsString(
            "select.setAttribute('data-ui--select2-minimum-results-for-search-value', '-1');",
            $table,
            'La liste du nombre de lignes ne doit jamais ouvrir de champ de recherche.',
        );
        self::assertMatchesRegularExpression(
            '/class="dt-bulk-bar__select"[\s\S]{0,400}data-ui--select2-minimum-results-for-search-value="8"/',
            $table,
            'La liste des actions de masse ne cherche qu\'à partir de huit actions.',
        );
    }

    private static function source(string $controller): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/'.$controller;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
