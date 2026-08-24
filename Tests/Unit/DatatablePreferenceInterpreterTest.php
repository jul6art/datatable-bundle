<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit;

use Jul6Art\DatatableBundle\Preference\DatatablePreferenceInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The interpreter is what stands between a browser and a stored row, so the tests are written from
 * the outside: a payload a client could plausibly send, and what must come back.
 *
 * Every case here is a shape that actually reaches the endpoint — a stale blob written by an older
 * table, a name pasted from a spreadsheet, a client that marked two views as default because it
 * lost a race. None of them may cost the user the rest of their layout.
 */
#[CoversClass(DatatablePreferenceInterpreter::class)]
final class DatatablePreferenceInterpreterTest extends TestCase
{
    public function testNothingStoredYetIsNotAnError(): void
    {
        $interpreter = new DatatablePreferenceInterpreter();

        self::assertSame($interpreter->empty(), $interpreter->decode(null));
        self::assertSame($interpreter->empty(), $interpreter->decode(''));
    }

    /**
     * A row that cannot be read must cost the column layout, never the page. Truncated JSON is not
     * hypothetical: it is what a storage column too short for the blob produces.
     */
    #[DataProvider('unreadableBlobs')]
    public function testAnUnreadableBlobFallsBackToEmptyPreferences(string $blob): void
    {
        $interpreter = new DatatablePreferenceInterpreter();

        self::assertSame($interpreter->empty(), $interpreter->decode($blob));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableBlobs(): iterable
    {
        yield 'truncated' => ['{"columns":[{"key":"sk'];
        yield 'not json at all' => ['sku,name,type'];
        yield 'a scalar' => ['42'];
        yield 'null literal' => ['null'];
    }

    public function testTheArrayOrderIsTheDisplayOrder(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'columns' => [
                ['key' => 'name', 'visible' => true],
                ['key' => 'sku', 'visible' => false],
            ],
        ]);

        self::assertSame([
            ['key' => 'name', 'visible' => true],
            ['key' => 'sku', 'visible' => false],
        ], $preferences['columns']);
    }

    /**
     * `visible` defaults to true when absent. A client that only sends an order — which is what a
     * drag does — must not hide every column it moved.
     */
    public function testAColumnWithoutAVisibleFlagIsVisible(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret(['columns' => [['key' => 'sku']]]);

        self::assertSame([['key' => 'sku', 'visible' => true]], $preferences['columns']);
    }

    public function testDuplicateColumnsCollapseToTheFirst(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'columns' => [
                ['key' => 'sku', 'visible' => false],
                ['key' => 'sku', 'visible' => true],
            ],
        ]);

        self::assertSame([['key' => 'sku', 'visible' => false]], $preferences['columns']);
    }

    public function testAnUnknownSortDirectionBecomesAscending(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret(['sort' => ['key' => 'name', 'dir' => 'sideways']]);

        self::assertSame(['key' => 'name', 'dir' => 'asc'], $preferences['sort']);
    }

    public function testASortWithoutAKeyIsNoSort(): void
    {
        self::assertNull(new DatatablePreferenceInterpreter()->interpret(['sort' => ['dir' => 'desc']])['sort']);
    }

    /**
     * The id is derived from the name and never read from the payload: it is the list key the
     * client draws with, so a client that kept an id from a previous name would carry it forever.
     */
    public function testAViewIdIsDerivedFromItsNameAndNotFromThePayload(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['id' => 'whatever-the-client-said', 'name' => 'CDD Atelier']],
        ]);

        self::assertSame('cdd-atelier', $preferences['views'][0]['id']);
    }

    public function testTwoViewsWithTheSameNameGetDistinctIds(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => 'Actifs'], ['name' => 'Actifs'], ['name' => 'Actifs']],
        ]);

        self::assertSame(['actifs', 'actifs-2', 'actifs-3'], array_column($preferences['views'], 'id'));
    }

    /**
     * A name written outside the Latin alphabet slugs to nothing. A stable fallback keeps it
     * addressable instead of colliding with every other such name.
     */
    public function testANameThatSlugsToNothingStillGetsAnId(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret(['views' => [['name' => '日本語']]]);

        self::assertMatchesRegularExpression('/^view-[0-9a-f]{8}$/', $preferences['views'][0]['id']);
        self::assertSame('日本語', $preferences['views'][0]['name']);
    }

    public function testAViewWithoutANameIsDropped(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => '   '], ['name' => 'Actifs'], ['filters' => ['isActive' => 'true']]],
        ]);

        self::assertCount(1, $preferences['views']);
        self::assertSame('Actifs', $preferences['views'][0]['name']);
    }

    /**
     * One default at most, and the FIRST wins rather than the last: a payload where a client marked
     * two must resolve the same way on every save, or the star moves on its own.
     */
    public function testOnlyOneViewCanBeTheDefault(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [
                ['name' => 'Actifs', 'default' => true],
                ['name' => 'Inactifs', 'default' => true],
            ],
        ]);

        self::assertSame([true, false], array_column($preferences['views'], 'default'));
    }

    public function testAFilterValueMayBeAListAndScalarsAreStringified(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => 'Mix', 'filters' => [
                'status' => ['draft', 'sent'],
                'isActive' => true,
                'category' => 12,
            ]]],
        ]);

        self::assertSame([
            'status' => ['draft', 'sent'],
            'isActive' => 'true',
            'category' => '12',
        ], $preferences['views'][0]['filters']);
    }

    public function testAnEmptyFilterValueIsDroppedRatherThanStored(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => 'Vide', 'filters' => ['status' => '', 'type' => [], 'sku' => null]]],
        ]);

        self::assertSame([], $preferences['views'][0]['filters']);
    }

    /**
     * A control character in a stored key would come back inside an HTML attribute. One EMBEDDED in
     * the key is refused at the door rather than escaped everywhere the key is read.
     *
     * Trailing whitespace and NUL are a different case: `trim()` removes them, so `"sku\0"` is
     * cleaned to `"sku"` and kept. That is the right call — it is a transport artefact, not a key.
     */
    public function testAKeyCarryingControlCharactersIsRefused(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'columns' => [['key' => "sk\x1Fu"], ['key' => " sku\x00"], ['key' => 'name']],
        ]);

        self::assertSame([
            ['key' => 'sku', 'visible' => true],
            ['key' => 'name', 'visible' => true],
        ], $preferences['columns']);
    }

    /**
     * Brackets and dots are the vocabulary of API Platform filters (`createdAt[after]`,
     * `assignments.department.label`) — the guard is on shape and length, not on an allowlist.
     */
    public function testAFilterParameterKeepsItsBracketsAndDots(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => 'Récents', 'filters' => [
                'createdAt[after]' => '2026-01-01',
                'assignments.department.label' => 'Atelier',
            ]]],
        ]);

        self::assertSame(
            ['createdAt[after]', 'assignments.department.label'],
            array_keys($preferences['views'][0]['filters']),
        );
    }

    /**
     * Cut on a CHARACTER boundary, not a byte one: half a multi-byte character stored is invalid
     * UTF-8, and it is the NEXT read that fails, on a row nothing else explains.
     */
    public function testALongNameIsCutOnACharacterBoundary(): void
    {
        $preferences = new DatatablePreferenceInterpreter()->interpret([
            'views' => [['name' => str_repeat('é', 100)]],
        ]);

        $name = $preferences['views'][0]['name'];

        self::assertSame(DatatablePreferenceInterpreter::MAX_NAME_LENGTH, mb_strlen($name));
        self::assertSame($name, mb_convert_encoding($name, 'UTF-8', 'UTF-8'), 'Le nom tronqué doit rester de l\'UTF-8 valide.');
    }

    public function testTheCountsAreBounded(): void
    {
        $interpreter = new DatatablePreferenceInterpreter();

        $preferences = $interpreter->interpret([
            'columns' => array_map(static fn (int $i): array => ['key' => 'col'.$i], range(1, 150)),
            'views' => array_map(static fn (int $i): array => ['name' => 'Vue '.$i], range(1, 40)),
        ]);

        self::assertCount(DatatablePreferenceInterpreter::MAX_COLUMNS, $preferences['columns']);
        self::assertCount(DatatablePreferenceInterpreter::MAX_VIEWS, $preferences['views']);
    }

    /**
     * The blob is shortened by dropping views from the END rather than refusing the save: the user
     * has just named one, and refusing would lose the column layout of the same request.
     */
    public function testAnOversizedBlobLosesItsLastViewsRatherThanTheWholeSave(): void
    {
        $interpreter = new DatatablePreferenceInterpreter();

        $preferences = $interpreter->interpret([
            'columns' => [['key' => 'sku']],
            'views' => array_map(static fn (int $i): array => [
                'name' => 'Vue '.$i,
                'filters' => array_combine(
                    array_map(static fn (int $j): string => 'param'.$j, range(1, 40)),
                    array_fill(0, 40, str_repeat('x', 255)),
                ),
            ], range(1, 20)),
        ]);

        $json = $interpreter->encode($preferences);

        self::assertLessThanOrEqual(DatatablePreferenceInterpreter::MAX_BYTES, \strlen($json));
        $stored = $interpreter->decode($json);
        self::assertNotSame([], $stored['views'], 'Il doit rester des vues, pas zéro.');
        self::assertLessThan(20, \count($stored['views']));
        self::assertSame([['key' => 'sku', 'visible' => true]], $stored['columns'], 'La mise en page des colonnes survit.');
    }

    /**
     * Round-trip: what is encoded is what comes back. Not a tautology — `encode()` may shorten, so
     * this is the assertion that it does not shorten what already fits.
     */
    public function testEncodeAndDecodeAreInverses(): void
    {
        $interpreter = new DatatablePreferenceInterpreter();
        $preferences = $interpreter->interpret([
            'columns' => [['key' => 'sku', 'visible' => true], ['key' => 'name', 'visible' => false]],
            'sort' => ['key' => 'name', 'dir' => 'desc'],
            'views' => [['name' => 'Actifs', 'filters' => ['isActive' => 'true'], 'default' => true]],
        ]);

        self::assertSame($preferences, $interpreter->decode($interpreter->encode($preferences)));
    }

    /**
     * The version is stamped from the first schema, before there is anything to distinguish. A
     * migration that has to guess which shape a row is in is a migration that drops preferences.
     */
    public function testEveryBlobCarriesItsSchemaVersion(): void
    {
        $interpreter = new DatatablePreferenceInterpreter();

        self::assertStringContainsString('"v":'.DatatablePreferenceInterpreter::SCHEMA_VERSION, $interpreter->encode($interpreter->empty()));
    }
}
