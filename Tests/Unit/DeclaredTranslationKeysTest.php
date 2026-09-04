<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use Jul6Art\DatatableBundle\Translation\DeclaredTranslationKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The keys the controller reads without a scanner being able to see them.
 *
 * ## What this replaces
 *
 * `datatable_status_map()` and `datatable_bulk_translations()` used to TRANSPORT these labels:
 * translate them server-side, nest them in a tree, and post the tree into an HTML attribute. With
 * the catalogue in the browser, the transport is gone — the renderer reads
 * `datatable.work_order_status.draft` straight from it.
 *
 * What survives is the DECLARATION. No scanner can guess `WorkOrderStatus::cases()` from a
 * template literal, so the enumeration a project already writes in `datatable.status_maps` becomes
 * what tells the guard which keys are alive.
 */
#[CoversClass(DeclaredTranslationKeys::class)]
final class DeclaredTranslationKeysTest extends TestCase
{
    /**
     * ⚠️ These six are read through a variable — `_buildPanel('columns', …, 'datatable.columns.button')`
     * hands the key down two calls before `this.t()` sees it. Invisible to the scanner, required
     * on every table that shows the preferences panel.
     */
    public function testThePanelAndRangeLabelsAreRequired(): void
    {
        $required = new DeclaredTranslationKeys()->keys();

        foreach ([
            'datatable.columns.button',
            'datatable.columns.hint',
            'datatable.views.button',
            'datatable.views.hint',
            'datatable.daterange.from',
            'datatable.daterange.to',
        ] as $key) {
            self::assertContains($key, $required);
        }
    }

    /**
     * The generic confirmation text every modal falls back to. Absent, a delete confirmation shows
     * an empty body — `_modalText()` returns '' when neither the specific key nor this one hits.
     */
    public function testTheGenericConfirmationTextIsRequired(): void
    {
        $required = new DeclaredTranslationKeys()->keys();

        self::assertContains('datatable.confirm.title', $required);
        self::assertContains('datatable.confirm.message', $required);
        self::assertContains('datatable.confirm.confirm', $required);
    }

    public function testAStatusMapContributesOneKeyPerCase(): void
    {
        $keys = new DeclaredTranslationKeys([
            'work_order_status' => ['key_prefix' => 'datatable.work_order_status.', 'keys' => ['draft', 'done']],
        ])->keys();

        self::assertContains('datatable.work_order_status.draft', $keys);
        self::assertContains('datatable.work_order_status.done', $keys);
    }

    /**
     * ⚠️ The key prefix is the CATALOGUE's, not a path inside a JSON tree. It used to be both:
     * `path` said where to nest the dictionary, `key_prefix` where to read it from. There is no
     * tree any more, so `superp`'s `sirh.expense.status.` prefix is simply the key it always was.
     */
    public function testThePrefixIsTheCatalogueKeyAndNotAPath(): void
    {
        $keys = new DeclaredTranslationKeys([
            'sirh_expense_status' => ['key_prefix' => 'sirh.expense.status.', 'keys' => ['draft']],
        ])->keys();

        self::assertSame(['sirh.expense.status.draft'], array_values(array_filter(
            $keys,
            static fn (string $key): bool => str_starts_with($key, 'sirh.'),
        )));
    }

    public function testKeysAreSortedAndUnique(): void
    {
        $keys = new DeclaredTranslationKeys([
            'a' => ['key_prefix' => 'datatable.shared.', 'keys' => ['one']],
            'b' => ['key_prefix' => 'datatable.shared.', 'keys' => ['one']],
        ])->keys();

        self::assertSame(array_values(array_unique($keys)), $keys);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys);
    }

    /**
     * ⚠️ A prefix, not keys. `_modalText()` looks up `datatable.modal.<type>.<field>` and FALLS
     * BACK to the generic text when it is absent — so requiring every combination would fail every
     * project that customises none, and requiring none would report every customised one as dead.
     */
    public function testTheModalOverridesAreAPrefix(): void
    {
        self::assertSame(['datatable.modal.'], new DeclaredTranslationKeys()->prefixes());
    }
}
