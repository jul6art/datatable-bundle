<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A table can OPEN on a search term the page hands it.
 *
 * A header search panel says "47 results" and links to the list, filtered by the same term. The
 * table read its opening search from `sessionStorage` only: the link landed on the unfiltered list —
 * or worse, on the term of the previous visit — and the count the user had just read matched
 * nothing on screen (cegeta ADR-0037).
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (same reasoning as `Select2ClearSourceTest`). What is
 * pinned is that three decisions are still MADE in the file.
 */
#[CoversNothing]
final class InitialSearchSourceTest extends TestCase
{
    public function testTheTableDeclaresAnInitialSearchValue(): void
    {
        self::assertMatchesRegularExpression(
            "/initialSearch:\\s*\\{\\s*type:\\s*String,\\s*default:\\s*''\\s*\\}/",
            self::source(),
            'La table doit accepter un terme de recherche d\'ouverture (`initial-search-value`).',
        );
    }

    /**
     * The page's term wins over the remembered one — the link is what the user just clicked — and
     * only on the first build: a column drag must not bring it back over what was typed since.
     */
    public function testThePagesTermWinsOverTheRememberedOneOnTheFirstBuildOnly(): void
    {
        self::assertMatchesRegularExpression(
            "/!rebuild\\s*&&\\s*''\\s*!==\\s*this\\.initialSearchValue/",
            self::source(),
            'Le terme de la page doit primer sur celui de la session, à la première construction seulement.',
        );
    }

    /**
     * The visible box shows the term the query carries, not the stale remembered one.
     */
    public function testTheSearchBoxShowsTheOpeningTerm(): void
    {
        self::assertMatchesRegularExpression(
            '/_restoreState\(saved,\s*openingSearch\)/',
            self::source(),
            'Le champ de recherche doit afficher le terme d\'ouverture effectivement envoyé.',
        );
    }

    /**
     * A page's term opens the table on THAT term alone: neither the session's filters nor the
     * starred view's. "See all 7 results" landing on "1 to 2 of 2" because a status filter was
     * still remembered from earlier is the count mismatch the link exists to avoid (cegeta
     * ADR-0037, REVIEWER 2026-09-23).
     */
    public function testThePagesTermOpensTheTableWithoutRememberedFilters(): void
    {
        self::assertMatchesRegularExpression(
            "/_openingFilters\\(saved\\)\\s*\\{\\s*(?:\\/\\/[^\\n]*\\s*)*if\\s*\\(''\\s*!==\\s*this\\.initialSearchValue\\)\\s*\\{?\\s*return\\s*\\{\\};/",
            self::source(),
            'Un terme donné par la page doit ouvrir la table sans filtre mémorisé ni vue étoilée.',
        );
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
