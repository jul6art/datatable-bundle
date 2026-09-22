<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The clear button of the Select2 controller never throws.
 *
 * Select2's clear button restores `placeholder.id` (`AllowClear._handleClear`, Select2 4.1.0). With
 * the button on and no placeholder, it throws "Cannot read properties of undefined (reading 'id')"
 * on every click and clears nothing. The controller turned the button on BY DEFAULT and only had a
 * placeholder when the page passed one: every Select2 of two back-offices out of three offered a
 * dead button (cereezer report 2026-09-22 § P1-a).
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (same reasoning as admin-bundle's
 * `KeyboardBehaviourSourceTest`). What is pinned is that two decisions are still MADE in the file:
 * the placeholder falls back to the empty `<option>` a Symfony `placeholder` renders, and the clear
 * button depends on a placeholder having been found.
 */
#[CoversNothing]
final class Select2ClearSourceTest extends TestCase
{
    /**
     * The empty `<option>` — Symfony's `placeholder`, or a hand-written "All" — is exactly the value
     * the clear button has to restore. An explicit placeholder still wins.
     */
    public function testThePlaceholderFallsBackToTheEmptyOption(): void
    {
        self::assertMatchesRegularExpression(
            "/''\\s*===\\s*option\\.value/",
            self::source(),
            'Sans placeholder explicite, le contrôleur doit le déduire de l\'option vide.',
        );
    }

    /**
     * Nothing to restore means nothing to clear: the button is offered only when a placeholder was
     * resolved.
     */
    public function testTheClearButtonRequiresAResolvedPlaceholder(): void
    {
        self::assertMatchesRegularExpression(
            '/allowClear:\s*this\.allowClearValue\s*&&\s*null\s*!==\s*placeholder/',
            self::source(),
            'La croix ne doit être offerte que si un placeholder a été résolu.',
        );
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/select2_controller.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
