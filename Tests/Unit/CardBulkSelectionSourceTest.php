<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Below 1024px, a row is selected from its card.
 *
 * The table gives way to cards under 1024px, and the checkbox column went with it: a bulk action —
 * validating twenty guides, deleting a batch — could only be run from a desktop (devingame,
 * 2026-10-27). Each card now carries a box in its header, and the header's « select all » stands
 * above the list; both write the selection the table's column writes.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (see `Select2ClearSourceTest`). What is pinned is that the
 * decisions are still made in the file.
 */
#[CoversNothing]
final class CardBulkSelectionSourceTest extends TestCase
{
    /** A card of a table with bulk actions has its box, ticked when its row is in the selection. */
    public function testACardCarriesItsBox(): void
    {
        $card = self::method('_renderCard', '(row, columns)');

        self::assertStringContainsString('this._bulkActions.length > 0', $card);
        self::assertMatchesRegularExpression('/class="dt-card-select" data-id="\$\{this\._escAttr\(id\)\}"/', $card);
        self::assertStringContainsString("this.t('datatable.bulk.select_row')", $card, 'La case doit porter le nom de celle du tableau.');
        self::assertStringContainsString('this._bulkSelection.has(id)', $card, 'Une carte redessinée doit garder sa case cochée.');
        self::assertStringContainsString('${bulkHtml}', $card);
    }

    /** The cards are redrawn without a draw of the table — a column toggled —, so their boxes are listened to on the list. */
    public function testTheBoxesAreListenedToOnTheList(): void
    {
        self::assertStringContainsString(
            "this._cardContainer.addEventListener('change', (event) => this._onCardBoxChange(event));",
            self::method('_renderCards'),
        );
        self::assertStringContainsString('this._cardSelectAll()', self::method('_renderCards'));

        $change = self::method('_onCardBoxChange', '(event)');
        self::assertStringContainsString("contains('dt-card-select')", $change);
        self::assertStringContainsString("contains('dt-card-select-all__box')", $change);
        self::assertStringContainsString('this._toggleBulkRows(', $change);
    }

    /** A row ticked on one side is ticked on the other: one selection, every box synchronised from it. */
    public function testEveryBoxShowsTheSameSelection(): void
    {
        $sync = self::method('_syncBulkBoxes');

        self::assertStringContainsString("'.dt-bulk-select-row, .dt-card-select'", $sync);
        self::assertStringContainsString('this._bulkSelection.has(cb.dataset.id)', $sync);

        self::assertStringContainsString("['.dt-card-select-all__box', '.dt-card-select']", self::method('_syncSelectAllCheckbox'));
        self::assertStringContainsString('this._syncBulkBoxes();', self::method('_clearBulkSelection'));
        self::assertStringContainsString('this._syncBulkBoxes();', self::method('_wireBulkCheckboxes'));
    }

    public function testTheBoxesAreStyled(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/datatable.css');

        self::assertMatchesRegularExpression('/\.dt-card-select,\s*\.dt-card-select-all__box\s*\{[^}]*w-5 h-5/', $css);
        self::assertStringContainsString('.dt-card-select-all {', $css);
    }

    /**
     * The bar sat above the first card: a row ticked further down changed a count nobody could see
     * (2.13.2). Below 1024px it is fixed at the foot of the screen, and the cards keep room for it.
     */
    public function testTheBarFollowsTheScreenBelow1024Pixels(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/datatable.css');

        self::assertMatchesRegularExpression(
            '/@media \(max-width: 1024px\) \{\s*\.dt-bulk-bar \{\s*@apply fixed inset-x-0 bottom-0 z-40 [^;]*bg-accent-50 dark:bg-slate-800;/',
            $css,
            'Sous 1024 px, la barre de sélection doit être fixée au pied de l\'écran, opaque.',
        );
        self::assertMatchesRegularExpression('/\.dt-card-list\.has-bulk-bar \{\s*padding-bottom: 10rem;/', $css);
        self::assertStringContainsString(
            "this._cardContainer?.classList.toggle('has-bulk-bar', count > 0);",
            self::method('_renderBulkBar'),
            'Les cartes doivent garder la place de la barre tant qu\'elle s\'affiche.',
        );
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
