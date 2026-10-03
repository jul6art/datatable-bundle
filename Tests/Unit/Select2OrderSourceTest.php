<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every Select2 autocomplete asks for its suggestions in alphabetical order.
 *
 * None of the three request builders — the form picker (`select2_controller.js`), the column filter
 * and the mobile filter sheet (`datatable_controller.js`) — sent an order: the collection answered in
 * its own default order, the TABLE's, most often "newest first". Sites came back as "Client 070 —
 * site 2", "Client 070 — site 1", "Client 069 — site 3"… (cereezer report 2026-10-03, P2).
 *
 * The request now carries `order[<key>]=asc`, where the key is the one DISPLAYED unless the caller
 * names another (`orderKey`), and `'none'` opts out — for a list whose server order IS the meaning.
 * An API Platform collection ignores an order on a property it does not declare (measured), so the
 * default is safe: an endpoint that cannot sort on the displayed key keeps answering as before.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that the
 * three builders share ONE function — they were three copies, which is how a fix lands in one and
 * not the others — and that this function asks for an ascending order.
 */
#[CoversNothing]
final class Select2OrderSourceTest extends TestCase
{
    public function testTheSharedQueryBuilderAsksForAnAscendingOrder(): void
    {
        $source = self::read('select2-config.js');

        self::assertMatchesRegularExpression('/export function ajaxQuery\(/', $source);
        self::assertMatchesRegularExpression("/`order\\[\\\$\\{orderKey\\}\\]`\\]\\s*=\\s*'asc'/", $source);
    }

    public function testTheFormPickerUsesTheSharedBuilderWithItsOrderKey(): void
    {
        $source = self::read('controllers/select2_controller.js');

        self::assertMatchesRegularExpression('/orderKey:\s*\{\s*type:\s*String/', $source, 'The picker declares an `orderKey` value.');
        self::assertStringContainsString('ajaxQuery(', $source);
    }

    public function testBothFilterBuildersUseTheSharedBuilder(): void
    {
        $source = self::read('controllers/datatable_controller.js');

        self::assertSame(2, substr_count($source, 'ajaxQuery('), 'The column filter AND the mobile sheet build their request with `ajaxQuery()`.');
        self::assertStringContainsString('filterOrderKey', $source, 'The filter carries its order key from the configuration.');
    }

    /**
     * The three copies are gone: the page size lives in one place. A fourth builder written by hand
     * would reintroduce the unordered list without anything turning red.
     */
    public function testNoBuilderSetsThePageSizeByHandAnyMore(): void
    {
        foreach (['controllers/select2_controller.js', 'controllers/datatable_controller.js'] as $file) {
            self::assertStringNotContainsString('query.size = 20', self::read($file), $file);
        }
    }

    private static function read(string $file): string
    {
        $path = \dirname(__DIR__, 2).'/assets/'.$file;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
