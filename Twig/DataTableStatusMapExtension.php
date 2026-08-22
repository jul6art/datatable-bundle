<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Turns a named set of enum keys into a translated dictionary, nested under the path the
 * JavaScript renderers read.
 *
 * A badge renderer resolves its label through `this.t('datatable.<map>.<key>')`, which walks the
 * `translations` value segment by segment. So the label of an ERP quote status lives at
 * `translationsValue.datatable.erp_quote_status.draft`, and something has to build that tree.
 *
 * ```twig
 * {% include '@Datatable/datatable/_translations.html.twig' with {
 *     extra_translations: datatable_status_map(['erp_quote_status', 'country']),
 * } %}
 * ```
 *
 * ## Why the maps are configuration and not constants
 *
 * They are a catalogue of the application's enums — `draft / sent / accepted / rejected` is
 * business vocabulary, not infrastructure. A bundle that shipped them would be shipping one
 * project's domain model to every other project. So the table lives in `config/packages/`:
 *
 * ```yaml
 * datatable:
 *     status_maps:
 *         erp_quote_status:
 *             keys: [draft, sent, accepted, rejected, expired, converted]
 *         sirh_expense_status:
 *             domain: sirh
 *             key_prefix: 'sirh.expense.status.'
 *             keys: [draft, submitted, approved, rejected, reimbursed]
 *         country:
 *             path: [organization, country]
 *             key_prefix: 'organization.country.'
 *             keys: [fr, be, lu]
 * ```
 *
 * `path` defaults to `[datatable, <name>]` and `key_prefix` to `datatable.<name>.`, which is what
 * every ordinary map wants; both exist for the ones that are read from somewhere else in the tree.
 *
 * > ⚠️ **An unknown map name throws.** Rendering the raw name, or an empty dictionary, would give
 * > a table whose badges are silently unlabelled — a defect nobody sees until a user reports a
 * > blank column. A typo has to surface at the first render.
 */
final readonly class DataTableStatusMapExtension
{
    /**
     * @param array<string, array{path: list<string>, domain: string, key_prefix: string, keys: list<string>}> $maps
     */
    public function __construct(
        private TranslatorInterface $translator,
        private array $maps = [],
    ) {
    }

    /**
     * @param string|list<string> $names a single map name, or several whose trees are deep-merged
     *
     * @return array<array-key, mixed> un dictionnaire imbriqué ; `array-key` pour la même raison
     *                                 que `deepMerge()` — la fusion ne prouve pas la nature des
     *                                 clés qu'elle traverse
     *
     * @throws \InvalidArgumentException when a name is not declared in `datatable.status_maps`
     */
    #[AsTwigFunction(name: 'datatable_status_map')]
    public function statusMap(string|array $names): array
    {
        $out = [];

        foreach (\is_string($names) ? [$names] : $names as $name) {
            if (!isset($this->maps[$name])) {
                throw new \InvalidArgumentException(\sprintf('Unknown datatable status map "%s". Declared maps: %s.', $name, [] === $this->maps ? '(none)' : implode(', ', array_keys($this->maps))));
            }

            $map = $this->maps[$name];

            $translated = [];
            foreach ($map['keys'] as $key) {
                $translated[$key] = $this->translator->trans($map['key_prefix'].$key, [], $map['domain']);
            }

            $out = self::deepMerge($out, self::wrapInPath($map['path'], $translated));
        }

        return $out;
    }

    /**
     * @param list<string>          $path
     * @param array<string, string> $leaf
     *
     * @return array<string, mixed>
     */
    private static function wrapInPath(array $path, array $leaf): array
    {
        $node = $leaf;
        foreach (array_reverse($path) as $segment) {
            $node = [$segment => $node];
        }

        return $node;
    }

    /**
     * ⚠️ `array-key` et non `string` : à l'intérieur de la récursion, rien ne prouve que les clés
     * d'un sous-tableau sont des chaînes — elles le sont en pratique, mais l'affirmer obligerait à
     * poser une suppression d'analyse à chaque appel récursif. On promet ce qui est démontrable.
     *
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     *
     * @return array<array-key, mixed>
     */
    private static function deepMerge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $existing = $left[$key] ?? null;
            if (\is_array($value) && \is_array($existing)) {
                $left[$key] = self::deepMerge($existing, $value);
            } else {
                $left[$key] = $value;
            }
        }

        return $left;
    }
}
