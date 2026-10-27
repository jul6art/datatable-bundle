<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The page-length list is a Select2, like every other list the table draws.
 *
 * DataTables builds it as a native `<select>` and the stylesheet only dressed it: in a back office
 * whose every list is a Select2, one native list stood above each table (devingame, 2026-10-27).
 * The project worked around it in its own entry point; every other project kept the native list.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that the
 * three decisions are still made in the file: the list gets the `ui--select2` controller without a
 * clear button, right after the table is built, and a length set by the code refreshes the widget.
 */
#[CoversNothing]
final class LengthSelectSourceTest extends TestCase
{
    public function testTheLengthListIsHandedToTheSelect2Controller(): void
    {
        $method = self::method('_enhanceLengthSelect');

        self::assertStringContainsString("querySelector('.dt-length select')", $method);
        self::assertMatchesRegularExpression(
            "/setAttribute\\('data-controller',\\s*'ui--select2'\\)/",
            $method,
            'La liste du nombre de lignes doit monter le contrôleur `ui--select2`.',
        );
        self::assertMatchesRegularExpression(
            "/setAttribute\\('data-ui--select2-allow-clear-value',\\s*'false'\\)/",
            $method,
            'Un nombre de lignes est toujours choisi : pas de croix pour le vider.',
        );
    }

    /**
     * A saved view sets the length by code: DataTables moves the native value, and Select2 keeps
     * showing the old one unless told.
     */
    public function testALengthSetByTheCodeRefreshesTheWidget(): void
    {
        self::assertMatchesRegularExpression(
            "/on\\('length\\.dt',[^\\n]*trigger\\('change\\.select2'\\)/",
            self::method('_enhanceLengthSelect'),
            'Un `length.dt` doit rafraîchir l\'affichage de Select2.',
        );
    }

    /** Right after the table is built: the list exists from then on, and a rebuild builds a new one. */
    public function testTheListIsEnhancedOnEveryBuild(): void
    {
        self::assertMatchesRegularExpression(
            '/this\.dataTable = new window\.DataTable\(this\.element, config\);[\s\S]{0,120}this\._enhanceLengthSelect\(\);/',
            self::source(),
        );
    }

    private static function method(string $name): string
    {
        self::assertSame(1, preg_match('/^    '.preg_quote($name, '/').'\(\) \{\n[\s\S]*?\n    \}\n/m', self::source(), $match), $name.' is gone.');

        return $match[0];
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
