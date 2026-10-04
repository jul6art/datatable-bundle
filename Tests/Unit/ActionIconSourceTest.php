<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every action icon a consumer asks for is in the controller's icon table.
 *
 * `getFaIcon()` resolves an action's `icon` name through a fixed table and falls back on
 * `fa-circle-question` for anything it does not know. Nothing fails: the button renders, it works, and
 * it shows a question mark. cereezer's quote and contract tables asked for `rotate-right` ("revise",
 * "renew") and `play` ("activate") and showed exactly that (cereezer review of 2026-10-04, which now
 * guards its own providers against this table).
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that each
 * name maps to its own Font Awesome glyph — and that the fallback is still a fallback, not a name.
 */
#[CoversNothing]
final class ActionIconSourceTest extends TestCase
{
    #[DataProvider('icons')]
    public function testTheIconIsKnown(string $name, string $glyph): void
    {
        self::assertMatchesRegularExpression(
            \sprintf("/^\\s+'?%s'?\\s*:\\s*'<i class=\"fa-solid %s\"><\\/i>',$/m", preg_quote($name, '/'), preg_quote($glyph, '/')),
            self::table(),
            \sprintf('`getFaIcon()` does not map "%s" to %s: the button would show a question mark.', $name, $glyph),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function icons(): iterable
    {
        yield 'activate' => ['play', 'fa-play'];
        yield 'revise, renew' => ['rotate-right', 'fa-rotate-right'];
    }

    public function testAnUnknownNameStillFallsBackOnTheQuestionMark(): void
    {
        $source = self::source();
        $start = strpos($source, 'getFaIcon(name) {');
        self::assertNotFalse($start, 'getFaIcon() is gone.');

        self::assertStringContainsString("return icons[name] || '<i class=\"fa-solid fa-circle-question\"></i>';", substr($source, $start, 6000));
    }

    /** The body of `getFaIcon()`, and nothing else: another table of the file must not satisfy the guard. */
    private static function table(): string
    {
        $source = self::source();
        $start = strpos($source, 'getFaIcon(name) {');
        $end = strpos($source, 'return icons[name]');
        self::assertNotFalse($start, 'getFaIcon() is gone.');
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
