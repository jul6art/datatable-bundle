<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The status tabs (2.13), held where they would silently go wrong.
 *
 * This bundle ships no JavaScript runner (same reasoning as {@see Select2ClearSourceTest}): each
 * case reads the line that decides, and says what breaks without it.
 */
#[CoversNothing]
final class StatusTabsSourceTest extends TestCase
{
    /**
     * A tab counts under the search and the OTHER filters, never under its own value nor a page
     * number — or every tab would show the count of the tab that is open, and the « All » tab the
     * size of one page.
     */
    public function testATabCountsUnderTheOtherFiltersAndAsksForOneRow(): void
    {
        $js = self::read('controllers/datatable_controller.js');

        self::assertStringContainsString("!['page', 'size', param].includes(key) && !key.startsWith('order[')", $js);
        self::assertStringContainsString("url.searchParams.set('size', '1');", $js, 'One row per tab: the count is read from the total.');
        self::assertStringContainsString('if (key === this._tabCountsKey) return;', $js, 'A page turn or a sort costs no count request.');
        self::assertMatchesRegularExpression('/this\._tabCountsKey = null;\s+this\._refreshStatusTabCounts\(\);\s+\}/', $js, 'The tabs are born after the first draw: they count at birth, or not before the next draw.');
        self::assertStringContainsString('sequence !== this._tabCountsSequence', $js, 'A late answer of an older query must not overwrite a newer count.');
        self::assertStringContainsString('new URL(this.apiUrlValue, document.baseURI)', $js, 'A frame whose origin reads "null" must not throw in the middle of a draw.');
    }

    /** The status lives in its tabs: a select for it in the header or the mobile sheet would be a second control for one filter. */
    public function testATabsFilterHasNoSelectAnywhereElse(): void
    {
        $js = self::read('controllers/datatable_controller.js');

        self::assertStringContainsString('const filterConfig = this.filtersValue.find(f => f.column === col.data && !f.tabs);', $js);
        self::assertStringContainsString('this.filtersValue.filter(config => !config.tabs).forEach(config => {', $js);
        self::assertStringContainsString('this._syncStatusTabs();', $js);
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/'.$path);
    }
}
