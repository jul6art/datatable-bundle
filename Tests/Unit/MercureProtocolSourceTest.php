<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The live-refresh bus speaks the Mercure protocol 1.0 (3.0).
 *
 * A 1.x hub answers `topic=` with a `400` once compatibility mode is off, refuses a token in the
 * `authorization` query parameter (RFC 9700), and rejects a request mixing `match=` with the 0.x
 * `lastEventID` — three ways for every table to stop refreshing without an error on the page.
 *
 * ## Why a source guard rather than a browser test
 *
 * This bundle ships no JavaScript runner (same reasoning as `Select2ClearSourceTest`).
 */
#[CoversNothing]
final class MercureProtocolSourceTest extends TestCase
{
    public function testTopicsAreMatchedExactly(): void
    {
        self::assertStringContainsString("url.searchParams.append('match', topic)", self::source());
        self::assertStringNotContainsString("'topic'", self::source(), 'Le paramètre `topic` est celui du protocole 0.x.');
    }

    /**
     * A family of channels is a URL Pattern since Mercure 1.0 (3.1): the token endpoint names them in
     * `patterns`, and the bus listens to each with `match_urlpattern=` — with `match=`, the hub would
     * compare `/organizations/:id/feed` letter by letter and forward nothing.
     */
    public function testPatternsAreMatchedAsUrlPatterns(): void
    {
        self::assertStringContainsString('data.patterns', self::source());
        self::assertStringContainsString("url.searchParams.append('match_urlpattern', pattern)", self::source());
    }

    /**
     * The token travels in the cookie the token endpoint sets — never in the URL, where a 1.x hub
     * refuses it and where proxies and browser histories would keep it.
     */
    public function testTheTokenTravelsInTheCookieOnly(): void
    {
        self::assertStringNotContainsString("'authorization'", self::source());
        self::assertStringContainsString('new EventSource(url.toString(), { withCredentials: true })', self::source());
    }

    public function testTheReconnectionIdUsesTheProtocol1Name(): void
    {
        self::assertStringContainsString("url.searchParams.set('last_event_id', lastEventId)", self::source());
        self::assertStringNotContainsString("'lastEventID'", self::source());
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 2).'/assets/services/mercure-bus.js';

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
