<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A view applied shows on its button, panel closed: the view's name in the label AND the selected colour — the
 * bookmark and the name in the accent, as a status tab chosen.
 *
 * The name alone, in grey, read as the button's own label (devingame, 2026-10-27); and on a phone, where the label
 * is hidden to the eye, the grey bookmark was all there was to see.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that the decision is still
 * made in the file, and that the stylesheet still colours it.
 */
#[CoversNothing]
final class ViewButtonSourceTest extends TestCase
{
    /** The button is marked whenever a view is on screen, unmarked as soon as none is — the label's own moment. */
    public function testTheButtonOfAnAppliedViewIsMarked(): void
    {
        $sync = self::method('_syncViewButtonLabel');

        self::assertStringContainsString(
            "label.closest('.dt-prefs-btn')?.classList.toggle('dt-prefs-btn--on', Boolean(active));",
            $sync,
            'Le bouton d\'une vue appliquée doit prendre la couleur sélectionnée, et la perdre avec elle.',
        );
    }

    /** The mark is the selected colour of the status tabs, written after the button's own rule so that it wins. */
    public function testTheMarkIsTheSelectedColour(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/datatable.css');

        self::assertMatchesRegularExpression(
            '/\.dt-prefs-btn--on \{\s*@apply [^;]*\bbg-accent-50 text-accent-700\b[^;]*\bdark:text-accent-300\b/',
            $css,
            'Une vue appliquée doit colorer le bouton comme un onglet de statut choisi.',
        );
        self::assertGreaterThan((int) strpos($css, '.dt-prefs-btn {'), (int) strpos($css, '.dt-prefs-btn--on {'), 'La marque doit suivre la règle du bouton, sinon le gris l\'emporte.');
    }

    private static function method(string $name, string $arguments = '()'): string
    {
        self::assertSame(1, preg_match('/^    '.preg_quote($name.$arguments, '/').' \{\n[\s\S]*?\n    \}\n/m', self::source(), $match), $name.' is gone.');

        return $match[0];
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
