<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The mobile CARD reads a column's value the way DataTables reads it for the table: through its DOTTED path.
 *
 * A column declared `user.email` renders in the desktop table — DataTables walks `row.user.email` — and rendered
 * nothing in the card: `_renderCard()` read `row['user.email']`, a key that does not exist, so the primary line showed a
 * dash and every nested secondary field vanished. Nothing failed: `undefined` is not an error, and the desktop table
 * was fine (cegeta ADR-0035, the member table under 640 px).
 *
 * This bundle ships no JavaScript runner (same reasoning as `Select2ClearSourceTest`): what is pinned is that the card
 * no longer indexes the row by the raw path, and that the resolver it uses walks the dots.
 */
#[CoversNothing]
final class CardDottedPathSourceTest extends TestCase
{
    public function testTheCardNeverIndexesTheRowByTheRawPath(): void
    {
        $card = self::method('_renderCard');

        self::assertDoesNotMatchRegularExpression('/row\[(?:primaryCol|c)\.data\]/', $card, 'La carte lit encore `row[col.data]` : un chemin pointé n\'y rend rien.');
        self::assertMatchesRegularExpression('/this\._valueAt\(row,\s*primaryCol\.data\)/', $card);
        self::assertMatchesRegularExpression('/this\._valueAt\(row,\s*c\.data\)/', $card);
    }

    public function testTheResolverWalksTheDots(): void
    {
        $resolver = self::method('_valueAt');

        self::assertMatchesRegularExpression("/split\\('\\.'\\)/", $resolver, 'Le résolveur doit parcourir le chemin segment par segment.');
        // A flat key that CONTAINS a dot is read as is first: a column named after a JSON-LD key must keep working.
        self::assertMatchesRegularExpression('/in\s+row/', $resolver);
    }

    private static function method(string $name): string
    {
        $source = self::source();
        $start = strpos($source, "\n    ".$name.'(');
        self::assertNotFalse($start, $name.'() est introuvable.');
        $end = strpos($source, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
