<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Translation;

/**
 * The translation keys this bundle's JavaScript reads without naming them in a way a scanner can
 * see — handed to a project's `AbstractJsTranslationTestCase` so the guard knows they are alive.
 *
 * ```php
 * protected static function declaredKeys(): array
 * {
 *     return static::getContainer()->get(DeclaredTranslationKeys::class)->keys();
 * }
 *
 * protected static function declaredPrefixes(): array
 * {
 *     return static::getContainer()->get(DeclaredTranslationKeys::class)->prefixes();
 * }
 * ```
 *
 * ## What this replaces
 *
 * `datatable_status_map()` and `datatable_bulk_translations()` used to TRANSPORT these labels:
 * translate them server-side, nest them in a tree, post the tree into an HTML attribute — 8.7 kB
 * of escaped HTML on every page carrying a table, re-sent on every request. With the catalogue in
 * the browser the transport is gone: a renderer reads `datatable.work_order_status.draft` from it
 * directly.
 *
 * What survives is the DECLARATION. No scanner can guess `WorkOrderStatus::cases()` out of a
 * template literal, so the enumeration a project already writes in `datatable.status_maps` earns
 * a second job: telling the guard which catalogue entries are read at runtime.
 *
 * ⚠️ `key_prefix` is now the CATALOGUE key, plainly. It used to share the work with `path`, which
 * said where to nest the dictionary inside the JSON tree — `superp` had maps whose two differed
 * (`path: [datatable, sirh_expense_status]`, `key_prefix: 'sirh.expense.status.'`). There is no
 * tree left, so `path` and `domain` are gone from the configuration.
 */
final readonly class DeclaredTranslationKeys
{
    /**
     * Keys the controller reads through a variable, so the scanner sees `this.t(labelKey)` and
     * nothing more.
     *
     * ⚠️ `datatable.confirm.{title,message,confirm}` is the generic text every confirmation modal
     * falls back to. Without it `_modalText()` returns an empty string and a delete confirmation
     * opens with a blank body — a dialog with two buttons and no question.
     *
     * @var list<string>
     */
    private const array CONTROLLER_KEYS = [
        'datatable.columns.button',
        'datatable.columns.hint',
        'datatable.confirm.confirm',
        'datatable.confirm.message',
        'datatable.confirm.title',
        'datatable.daterange.from',
        'datatable.daterange.to',
        'datatable.views.button',
        'datatable.views.hint',
    ];

    /**
     * ⚠️ A prefix, not a list of keys. `_modalText()` looks up `datatable.modal.<type>.<field>`
     * and falls back to the generic text when it is absent, so the absence is a designed
     * behaviour: requiring every combination would fail every project that customises none, and
     * declaring nothing would report every customised one as dead.
     *
     * @var list<string>
     */
    private const array OPTIONAL_PREFIXES = [
        'datatable.modal.',
    ];

    /**
     * @param array<string, array{key_prefix: string, keys: list<string>}> $statusMaps
     */
    public function __construct(
        private array $statusMaps = [],
    ) {
    }

    /**
     * @return list<string> sorted and deduplicated — two maps may legitimately share a prefix
     */
    public function keys(): array
    {
        $keys = self::CONTROLLER_KEYS;

        foreach ($this->statusMaps as $map) {
            foreach ($map['keys'] as $case) {
                $keys[] = $map['key_prefix'].$case;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return self::OPTIONAL_PREFIXES;
    }
}
