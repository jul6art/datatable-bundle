<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use Jul6Art\CoreBundle\Translation\JsTranslationScanner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Every translation key this bundle's JavaScript reads, checked as a whole.
 *
 * ## The defect this replaces
 *
 * `ControllerLocalisationTest` used to guard the bulk-selection aria-labels by asserting that the
 * controller contains `this.t('bulk.select_all')` **and** that the Twig partial contains
 * `'datatable.bulk.select_all'|trans`. Each half in its own file, never one against the other —
 * so it stayed green while the two halves named different keys, and every bulk checkbox of three
 * back-offices carried the literal string "bulk.select_all" as its aria-label.
 *
 * With the catalogue as the single source there is no second half to drift from: the key the
 * controller reads IS the key the project translates. What is left to guard is that the bundle
 * only ever asks for keys inside its own namespace — a project cannot be expected to translate
 * `bulk.select_all` when every other key it is handed starts with `datatable.`.
 */
#[CoversNothing]
final class AssetTranslationKeysTest extends TestCase
{
    /**
     * ⚠️ `datatable.` and nothing else. The prefix is what tells a project which keys are the
     * bundle's business, and it is what the missing-key report of `core:i18n:js-audit` groups on.
     */
    public function testEveryKeyLivesUnderTheBundleNamespace(): void
    {
        $scan = new JsTranslationScanner()->scan(\dirname(__DIR__, 2).'/assets');

        $foreign = array_values(array_filter(
            $scan->keys(),
            static fn (string $key): bool => !str_starts_with($key, 'datatable.'),
        ));

        self::assertSame([], $foreign, \sprintf(
            "These keys sit outside the bundle's namespace, so no project translates them:\n  - %s",
            implode("\n  - ", array_map(
                fn (string $key): string => \sprintf('%s (%s)', $key, implode(', ', $this->shorten($scan->occurrences($key)))),
                $foreign,
            )),
        ));
    }

    /**
     * The dynamic calls, listed rather than forbidden — `this.t(labelKey)` is how the preferences
     * panel labels its own buttons. Pinning the count means a new one has to be looked at.
     */
    public function testTheDynamicCallsAreTheKnownOnes(): void
    {
        $scan = new JsTranslationScanner()->scan(\dirname(__DIR__, 2).'/assets');

        self::assertCount(8, $scan->dynamicCalls(), \sprintf(
            "A call whose key cannot be read has appeared or gone:\n  - %s",
            implode("\n  - ", $this->shorten($scan->dynamicCalls())),
        ));
    }

    /**
     * @param list<string> $occurrences
     *
     * @return list<string>
     */
    private function shorten(array $occurrences): array
    {
        $root = \dirname(__DIR__, 2).'/';

        return array_map(static fn (string $o): string => str_replace($root, '', $o), $occurrences);
    }
}
