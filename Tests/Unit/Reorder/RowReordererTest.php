<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit\Reorder;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\DatatableBundle\Reorder\RowReorderer;
use Jul6Art\DatatableBundle\Tests\Fixtures\Entity\Lot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Le réordonnancement (2.9) : tout l'ordre en une transaction, ou rien — sur une vraie base
 * (SQLite en mémoire), parce que « une transaction » et « rien d'écrit » ne se prouvent pas sur un
 * double.
 */
#[CoversClass(RowReorderer::class)]
final class RowReordererTest extends TestCase
{
    private EntityManager $entityManager;

    /** @var list<string> */
    private array $queries = [];

    /** @var array<string, int> nom → identifiant */
    private array $ids = [];

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2).'/Fixtures/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setMiddlewares([new Middleware(new class(function (string $entry): void {
            $this->queries[] = $entry;
        }) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                // Une requête par son SQL, une transaction par le message qui l'annonce.
                $sql = $context['sql'] ?? null;
                ($this->record)(\is_string($sql) ? $sql : (string) $message);
            }
        })]);

        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($this->entityManager)->createSchema([$this->entityManager->getClassMetadata(Lot::class)]);

        foreach (['a', 'b', 'c'] as $position => $name) {
            $lot = new Lot($name, $position);
            $this->entityManager->persist($lot);
            $this->entityManager->flush();
            $this->ids[$name] = (int) $lot->id;
        }

        $this->entityManager->clear();
        $this->queries = [];
    }

    public function testTheWholeOrderIsWrittenInOneTransaction(): void
    {
        $written = new RowReorderer($this->entityManager)->reorder(Lot::class, 'position', [$this->ids['c'], $this->ids['a'], $this->ids['b']]);

        self::assertTrue($written);
        self::assertSame(['c' => 0, 'a' => 1, 'b' => 2], $this->positions());
        self::assertContains('Beginning transaction', $this->queries);
        self::assertContains('Committing transaction', $this->queries);
    }

    /** Une ligne qui garde sa place n'est pas réécrite : la mise à jour suit le changement. */
    public function testOnlyTheMovedRowsAreUpdated(): void
    {
        new RowReorderer($this->entityManager)->reorder(Lot::class, 'position', [$this->ids['a'], $this->ids['c'], $this->ids['b']]);

        self::assertCount(2, array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE')));
    }

    /** Des identifiants en chaînes (un JSON qui les cite) valent les entiers. */
    public function testStringIdentifiersAreTheSameRows(): void
    {
        self::assertTrue(new RowReorderer($this->entityManager)->reorder(Lot::class, 'position', [(string) $this->ids['b'], (string) $this->ids['a'], (string) $this->ids['c']]));
        self::assertSame(['b' => 0, 'a' => 1, 'c' => 2], $this->positions());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staleOrders(): iterable
    {
        yield 'une ligne manque' => [['a', 'b']];
        yield 'une ligne en trop' => [['a', 'b', 'c', 'x']];
        yield 'une ligne deux fois' => [['a', 'a', 'b']];
        yield 'une ligne inconnue' => [['a', 'b', 'x']];
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('staleOrders')]
    public function testAStaleOrderWritesNothing(array $names): void
    {
        $ids = array_map(fn (string $name): int => $this->ids[$name] ?? 999, $names);

        self::assertFalse(new RowReorderer($this->entityManager)->reorder(Lot::class, 'position', $ids));
        self::assertSame(['a' => 0, 'b' => 1, 'c' => 2], $this->positions());
    }

    public function testAFieldTheEntityDoesNotHaveIsAMistake(): void
    {
        $this->expectException(\LogicException::class);

        new RowReorderer($this->entityManager)->reorder(Lot::class, 'rank', [$this->ids['a']]);
    }

    /**
     * @return array<string, int>
     */
    private function positions(): array
    {
        $this->entityManager->clear();
        $positions = [];

        foreach ($this->entityManager->getRepository(Lot::class)->findBy([], ['position' => 'ASC']) as $lot) {
            $positions[$lot->name] = $lot->position;
        }

        return $positions;
    }
}
