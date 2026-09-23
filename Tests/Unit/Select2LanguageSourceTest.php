<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Select2 speaks the page's language without the project registering a locale file.
 *
 * The controller passed `language: document.documentElement.lang`, a locale CODE. Select2 only
 * translates a code whose `select2/dist/js/i18n/<code>.js` the project loaded, and two consumers
 * out of three had not: "Please enter 1 or more characters", "No results found", "Searching…"
 * showed in English under every autocomplete of a French back office (superp, 2026-09-23).
 *
 * ## Why a source guard
 *
 * This bundle ships no JavaScript runner (same reasoning as {@see Select2ClearSourceTest}). What is
 * pinned is that the controller hands Select2 an object built from the catalogue, and that the
 * object covers every message Select2 4.1 asks for — a missing one falls back to English, which is
 * the defect itself. That the keys are TRANSLATED is each project's `AbstractJsTranslationTestCase`,
 * which scans this directory.
 */
#[CoversNothing]
final class Select2LanguageSourceTest extends TestCase
{
    /**
     * The messages of Select2 4.1's English dictionary (`select2/i18n/en`) and the catalogue key
     * each one reads.
     */
    private const array MESSAGES = [
        'errorLoading' => 'datatable.select2.error_loading',
        'inputTooLong' => 'datatable.select2.input_too_long',
        'inputTooShort' => 'datatable.select2.input_too_short',
        'loadingMore' => 'datatable.select2.loading_more',
        'maximumSelected' => 'datatable.select2.maximum_selected',
        'noResults' => 'datatable.select2.no_results',
        'searching' => 'datatable.select2.searching',
        'removeAllItems' => 'datatable.select2.remove_all_items',
        'removeItem' => 'datatable.select2.remove_item',
        'search' => 'datatable.select2.search',
    ];

    public function testTheControllerPassesTheCatalogueAndNotALocaleCode(): void
    {
        $controller = self::read('controllers/select2_controller.js');

        self::assertMatchesRegularExpression(
            '/^\s*language:\s*select2Language\(\),$/m',
            $controller,
            'Select2 must receive the translated messages: a locale code translates nothing unless '
            .'the project registered its locale file.',
        );
        self::assertStringNotContainsString('documentElement.lang', $controller);
    }

    public function testEverySelect2MessageReadsItsCatalogueKey(): void
    {
        preg_match_all(
            "/^\\s*(\\w+):\\s*\\([^)]*\\)\\s*=>\\s*t\\('([a-z0-9_.]+)'/m",
            self::read('select2-language.js'),
            $found,
            \PREG_SET_ORDER,
        );

        $mapped = [];
        foreach ($found as [, $message, $key]) {
            $mapped[$message] = $key;
        }

        ksort($mapped);
        $expected = self::MESSAGES;
        ksort($expected);

        self::assertSame($expected, $mapped, 'A Select2 message left out of the object is shown in English.');
    }

    /**
     * Select2 computes the count; the catalogue receives it as `%count%`, the parameter shape of
     * every other entry of the `javascript` domain.
     */
    public function testTheCountingMessagesPassTheirCount(): void
    {
        $source = self::read('select2-language.js');

        foreach (['input_too_long' => 'input.length - maximum', 'input_too_short' => 'minimum - input.length', 'maximum_selected' => 'maximum'] as $key => $count) {
            self::assertStringContainsString(
                \sprintf("t('datatable.select2.%s', { '%%count%%': %s })", $key, $count),
                $source,
                \sprintf('`%s` must receive the count Select2 computes.', $key),
            );
        }
    }

    /**
     * ⚠️ A key the project has not translated yet must not reach the screen: the translator
     * returns the key itself, and `datatable.select2.no_results` under a field is worse than the
     * English it replaced. The untranslated message is dropped, so Select2 keeps its own.
     */
    public function testAnUntranslatedMessageFallsBackToSelect2sOwn(): void
    {
        self::assertMatchesRegularExpression(
            "/\\.filter\\(\\(\\[, message\\]\\) => !message\\(PROBE\\)\\.startsWith\\('datatable\\.select2\\.'\\)\\)/",
            self::read('select2-language.js'),
            'A message whose key is not translated must be left out of the object.',
        );
    }

    private static function read(string $file): string
    {
        $path = \dirname(__DIR__, 2).'/assets/'.$file;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
