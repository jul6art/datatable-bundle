<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * The translation block every datatable needs, and the deep-merge filter that lets a template add
 * its own on top.
 *
 * ```twig
 * <table data-controller="datatable"
 *        data-datatable-translations-value="{{
 *            { datatable: { … keys of this table … } }
 *            |merge_recursive(datatable_bulk_translations())
 *            |json_encode|e('html_attr') }}">
 * ```
 *
 * ## Why a Twig function rather than a partial full of keys
 *
 * The bulk bar, the per-row confirmation modal and the bulk confirmation modal each need a title,
 * a message and a confirm label **per action type**. Written out in Twig that is four lines times
 * the number of action types, repeated in every index template — and the day a type is added, the
 * templates that were not updated show a raw key in a modal.
 *
 * ## `merge_recursive`, and why `|merge` is not enough
 *
 * Twig's `|merge` merges the top level only, so merging `{datatable: {bulk: …}}` into
 * `{datatable: {status: …}}` drops `status` entirely. Nothing warns; the badges just stop having
 * labels.
 *
 * ## The action types are configuration
 *
 * `delete`, `activate`, `publish`… are the application's vocabulary. The bundle ships the ones any
 * back office has, and a project adds its own:
 *
 * ```yaml
 * datatable:
 *     bulk_actions: [invite, validate, approve_paid]   # merged over the defaults, never replacing
 * ```
 *
 * > ⚠️ **A type whose `modal.<type>.*` keys do not exist is skipped, not rendered.** The guard is
 * > the untranslated-key comparison below: declaring a type without its keys would otherwise push
 * > `modal.invite.title` into a modal title. The cost of the guard is that a genuinely missing
 * > translation falls back to the generic confirmation text instead of shouting — which is the
 * > right trade here, because the alternative shouts on every project that does not use every
 * > default type.
 */
final readonly class DataTableBulkExtension
{
    /**
     * The action types any back office has. A project's own types are merged over this list from
     * `datatable.bulk_actions` — the list is not replaced, so adding one keeps the others.
     *
     * @var list<string>
     */
    public const array DEFAULT_ACTION_TYPES = [
        'delete',
        'activate',
        'deactivate',
        'publish',
        'unpublish',
        'approve',
        'reject',
        'mark_read',
        'suspend',
        'reactivate',
        'restore',
        'change_status',
        'revoke',
    ];

    /**
     * @param list<string> $actionTypes
     */
    public function __construct(
        private TranslatorInterface $translator,
        private array $actionTypes = self::DEFAULT_ACTION_TYPES,
    ) {
    }

    /**
     * Deep-merges two dictionaries, the right-hand side winning on scalar collisions.
     *
     * ⚠️ `array-key` et non `string` : à l'intérieur de la récursion, rien ne prouve que les clés
     * d'un sous-tableau sont des chaînes — elles le sont en pratique, mais l'affirmer obligerait à
     * poser une suppression d'analyse à chaque appel récursif. On promet ce qui est démontrable.
     *
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     *
     * @return array<array-key, mixed>
     */
    #[AsTwigFilter(name: 'merge_recursive')]
    public function mergeRecursive(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $existing = $left[$key] ?? null;
            if (\is_array($value) && \is_array($existing)) {
                $left[$key] = $this->mergeRecursive($existing, $value);
            } else {
                $left[$key] = $value;
            }
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    #[AsTwigFunction(name: 'datatable_bulk_translations')]
    public function getTranslations(): array
    {
        $modalSingle = [];
        $modalBulk = [];

        foreach ($this->actionTypes as $type) {
            // Per-row confirmation. The controller looks these up as `datatable.modal.<type>.<key>`
            // and falls back to the generic `datatable.confirm.<key>` when a type has none.
            $singleTitle = $this->translator->trans('modal.'.$type.'.title');
            if ($singleTitle !== 'modal.'.$type.'.title') {
                $modalSingle[$type] = [
                    'title' => $singleTitle,
                    'message' => $this->translator->trans('modal.'.$type.'.message'),
                    'confirm' => $this->translator->trans('modal.'.$type.'.confirm'),
                ];
            }

            // Same guard: a type declared without its `modal.bulk.*` keys would push a raw key into
            // the bulk modal, where the per-row fallback carries `{field}` placeholders it has no
            // row to interpolate.
            $bulkTitle = $this->translator->trans('modal.bulk.'.$type.'.title');
            if ($bulkTitle !== 'modal.bulk.'.$type.'.title') {
                $modalBulk[$type] = [
                    'title' => $bulkTitle,
                    'message' => $this->translator->trans('modal.bulk.'.$type.'.message'),
                    'confirm' => $this->translator->trans('modal.bulk.'.$type.'.confirm'),
                ];
            }
        }

        return [
            'datatable' => [
                'bulk' => [
                    'selected' => $this->translator->trans('datatable.bulk.selected'),
                    'choose' => $this->translator->trans('datatable.bulk.choose'),
                    'apply' => $this->translator->trans('datatable.bulk.apply'),
                    'clear' => $this->translator->trans('datatable.bulk.clear'),
                ],
                'coalescence' => [
                    'message' => $this->translator->trans('datatable.coalescence.message'),
                    'refresh' => $this->translator->trans('datatable.coalescence.refresh'),
                ],
                'confirm' => [
                    'title' => $this->translator->trans('datatable.confirm.title'),
                    'message' => $this->translator->trans('datatable.confirm.message'),
                    'confirm' => $this->translator->trans('datatable.confirm.confirm'),
                    'cancel' => $this->translator->trans('datatable.confirm.cancel'),
                ],
                'modal' => array_merge(
                    $modalSingle,
                    [
                        'bulk' => $modalBulk,
                        // Read by `_subjectLabel()` to fill the `%subject%` of a generic message
                        // with the row's own label.
                        'subject_wrap' => $this->translator->trans('modal.subject_wrap'),
                        'subject_default' => $this->translator->trans('modal.subject_default'),
                    ],
                ),
            ],
        ];
    }
}
