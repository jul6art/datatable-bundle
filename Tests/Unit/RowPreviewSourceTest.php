<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The row preview (2.13), held where it would silently go wrong.
 *
 * This bundle ships no JavaScript runner (same reasoning as {@see Select2ClearSourceTest}): each
 * case reads the line that decides, and says what breaks without it.
 */
#[CoversNothing]
final class RowPreviewSourceTest extends TestCase
{
    /** A middle click or a modifier key on the eye keeps the browser's behaviour: a new tab. */
    public function testOnlyAPlainClickOpensThePreview(): void
    {
        self::assertStringContainsString(
            '0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey',
            self::read('controllers/datatable_controller.js'),
        );
    }

    /**
     * The panel shows the page's markup, never its scripts; what a page marks to keep survives the
     * region it sits in; Escape closes; focus returns to the row.
     */
    public function testThePreviewPanelDropsScriptsKeepsMarkedPanelsAndGivesFocusBack(): void
    {
        $js = self::read('services/preview-sheet.js');

        self::assertStringContainsString("part.querySelectorAll('script').forEach((element) => element.remove());", $js);
        self::assertStringContainsString("element.replaceWith(...element.querySelectorAll('[data-preview-keep]'));", $js);
        self::assertStringContainsString("if ('Escape' === event.key) closePreviewSheet();", $js);
        self::assertStringContainsString('if (trigger?.isConnected) trigger.focus();', $js);
        self::assertStringContainsString('controller.abort();', $js, 'The request of a replaced preview must not land in the new one.');
        self::assertStringContainsString("part.querySelectorAll('img[loading=\"lazy\"]').forEach((image) => image.setAttribute('loading', 'eager'));", $js, 'An imported lazy image never loads.');
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/'.$path);
    }
}
