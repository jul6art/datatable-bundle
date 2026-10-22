<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The ⋮ of a row's actions is an icon alone: it names itself — `datatable.actions`, English when the
 * application has no translation — and says it opens a menu (axe `button-name`, critical). Read in the
 * source: the markup is a JavaScript string, drawn only in a browser.
 */
final class ActionsDropdownNameSourceTest extends TestCase
{
    public function testTheActionsButtonNamesItselfAndHidesItsIcon(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../assets/controllers/datatable_controller.js');
        $start = strpos($source, '    _renderActionsDropdown(items) {');
        self::assertNotFalse($start, '_renderActionsDropdown() is gone.');
        $method = substr($source, $start, 3000);

        self::assertStringContainsString("this._orEnglish(this.t('datatable.actions'), 'Actions')", $method);
        self::assertMatchesRegularExpression('/data-dt-dropdown-toggle aria-label="\$\{label\}" aria-haspopup="menu"/', $method);
        self::assertStringContainsString('<i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>', $method);
    }
}
