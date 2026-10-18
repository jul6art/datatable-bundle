<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A table can match Mercure events on another IRI than its collection's address.
 *
 * The live refresh keeps an event when its IRI starts with the table's `apiUrl`. A collection served
 * at another address than its items — devinlive's "All galleries", `/api/moderation/galleries`,
 * whose rows are `/api/galleries/{id}` — never matched: every event was dropped, silently, and the
 * table never refreshed. `feed-iri-value` names the prefix to match instead.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (same reasoning as `Select2ClearSourceTest`).
 */
#[CoversNothing]
final class FeedIriSourceTest extends TestCase
{
    public function testTheTableDeclaresAFeedIriValue(): void
    {
        self::assertMatchesRegularExpression(
            "/feedIri:\\s*\\{\\s*type:\\s*String,\\s*default:\\s*''\\s*\\}/",
            self::source(),
            'La table doit accepter le préfixe d\'IRI de ses événements (`feed-iri-value`).',
        );
    }

    /** The declared prefix wins over the collection's address, and the address stays the default. */
    public function testTheFeedIriWinsOverTheCollectionsAddress(): void
    {
        self::assertMatchesRegularExpression(
            '/const baseApiUrl = \(this\.feedIriValue \|\| this\.apiUrlValue\)\.split/',
            self::source(),
            'Le préfixe déclaré doit primer sur l\'adresse de la collection pour apparier un événement.',
        );
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
