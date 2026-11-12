<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Jul6Art\DatatableBundle\Preference\Doctrine\AbstractDatatablePreference;
use Jul6Art\DatatableBundle\Preference\Doctrine\DoctrineDatatablePreferenceStore;
use Jul6Art\DatatableBundle\Tests\Fixtures\DoctrinePreferenceKernel;
use Jul6Art\DatatableBundle\Tests\Fixtures\Preference\Account;
use Jul6Art\DatatableBundle\Tests\Fixtures\Preference\TablePreference;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The Doctrine store of the preferences, on a real schema (SQLite in memory) — what the applications'
 * copies did, now shipped once: one row per account and table, replaced and not added to, removed on
 * reset, and one per space when a scope says so. The base class restores the exception handlers a
 * kernel leaves behind (on the lowest dependency set, Symfony's).
 */
#[CoversClass(DoctrineDatatablePreferenceStore::class)]
#[CoversClass(AbstractDatatablePreference::class)]
final class DoctrinePreferenceStoreTest extends AbstractFunctionalTestCase
{
    private ?DoctrinePreferenceKernel $kernel = null;

    #[\Override]
    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;

        parent::tearDown();
    }

    public function testTheStoreIsBoundWhenTheApplicationNamesItsEntity(): void
    {
        self::assertInstanceOf(DoctrineDatatablePreferenceStore::class, $this->store());
    }

    public function testNothingIsBoundWithoutTheEntityClass(): void
    {
        $container = $this->bootDoctrine(withStorage: false);

        self::assertFalse($container->has(DatatablePreferenceStoreInterface::class), 'An application without the key binds its own store.');
    }

    public function testTheOwnerResolvesToTheApplicationsAccount(): void
    {
        $this->bootDoctrine();

        self::assertSame(Account::class, $this->entityManager()->getClassMetadata(TablePreference::class)->getAssociationTargetClass('owner'));
    }

    public function testTheColumnsAreThoseOfTheApplicationsCopies(): void
    {
        $this->bootDoctrine();
        $metadata = $this->entityManager()->getClassMetadata(TablePreference::class);

        self::assertSame('datatable_preference', $metadata->getTableName());
        self::assertSame('datatable_key', $metadata->getColumnName('datatableKey'));
        self::assertSame('payload', $metadata->getColumnName('payload'));
        self::assertSame('owner_id', $metadata->getSingleAssociationJoinColumnName('owner'));
        self::assertSame(['createdAt', 'updatedAt'], array_values(array_intersect(['createdAt', 'updatedAt'], $metadata->getFieldNames())));
    }

    public function testNothingStoredReadsNull(): void
    {
        $store = $this->store();

        self::assertNull($store->read($this->account('ada@example.test'), 'erp_product'));
    }

    public function testAWriteIsReadBackAndASecondOneReplacesIt(): void
    {
        $store = $this->store();
        $ada = $this->account('ada@example.test');

        $store->write($ada, 'erp_product', '{"v":1,"views":[]}');
        $store->write($ada, 'erp_product', '{"v":1,"views":[{"name":"Second"}]}');

        self::assertSame('{"v":1,"views":[{"name":"Second"}]}', $store->read($ada, 'erp_product'));
        self::assertCount(1, $this->rows(), 'Replaced, not added to: one row per account and table.');
    }

    /**
     * The timestamps come from the base class's lifecycle callbacks, which the concrete entity
     * inherits without declaring `#[ORM\HasLifecycleCallbacks]` itself: without them, the creation time
     * would fail the insert and the update time would stay NULL in silence.
     */
    public function testTheConcreteEntityInheritsTheTimestamps(): void
    {
        $store = $this->store();
        $ada = $this->account('ada@example.test');

        $metadata = $this->entityManager()->getClassMetadata(TablePreference::class);
        $createdAt = $metadata->getColumnName('createdAt');
        $updatedAt = $metadata->getColumnName('updatedAt');

        $store->write($ada, 'erp_product', '{"v":1}');
        $created = $this->rows()[0];
        $store->write($ada, 'erp_product', '{"v":2}');
        $updated = $this->rows()[0];

        self::assertNotNull($created[$createdAt], 'PrePersist: the creation time is set.');
        self::assertNull($created[$updatedAt], 'Not updated yet.');
        self::assertNotNull($updated[$updatedAt], 'PreUpdate: a second write sets the update time.');
        self::assertSame($created[$createdAt], $updated[$createdAt], 'The creation time does not move.');
    }

    public function testDeletingRemovesTheRowAndDeletingNothingIsNotAnError(): void
    {
        $store = $this->store();
        $ada = $this->account('ada@example.test');
        $store->write($ada, 'erp_product', '{"v":1}');

        $store->delete($ada, 'erp_product');
        $store->delete($ada, 'erp_product');

        self::assertNull($store->read($ada, 'erp_product'));
        self::assertSame([], $this->rows());
    }

    public function testTwoAccountsAndTwoTablesAreIndependent(): void
    {
        $store = $this->store();
        $ada = $this->account('ada@example.test');
        $grace = $this->account('grace@example.test');

        $store->write($ada, 'erp_product', '{"who":"ada"}');
        $store->write($grace, 'erp_product', '{"who":"grace"}');
        $store->write($ada, 'erp_order', '{"table":"orders"}');

        self::assertSame('{"who":"ada"}', $store->read($ada, 'erp_product'));
        self::assertSame('{"who":"grace"}', $store->read($grace, 'erp_product'));
        self::assertSame('{"table":"orders"}', $store->read($ada, 'erp_order'));
        self::assertNull($store->read($grace, 'erp_order'));
    }

    public function testAnAccountOfAnotherClassReadsAndWritesNothing(): void
    {
        $store = $this->store();
        $machine = new InMemoryUser('agent', null);

        $store->write($machine, 'erp_product', '{"v":1}');

        self::assertNull($store->read($machine, 'erp_product'));
        self::assertSame([], $this->rows());
    }

    public function testAScopeKeepsOneArrangementPerSpace(): void
    {
        $store = $this->store(withScope: true);
        $ada = $this->account('ada@example.test', 'A');

        $store->write($ada, 'erp_product', '{"space":"A"}');
        $this->moveTo($ada, 'B');
        self::assertNull($store->read($ada, 'erp_product'), 'In space B, the defaults.');
        $store->write($ada, 'erp_product', '{"space":"B"}');
        $this->moveTo($ada, 'A');

        self::assertSame('{"space":"A"}', $store->read($ada, 'erp_product'), 'Back in space A, its arrangement.');
        self::assertCount(2, $this->rows());
    }

    public function testWithoutAScopeTheSpaceIsNotPartOfTheKey(): void
    {
        $store = $this->store();
        $ada = $this->account('ada@example.test', 'A');

        $store->write($ada, 'erp_product', '{"space":"A"}');
        $this->moveTo($ada, 'B');

        self::assertSame('{"space":"A"}', $store->read($ada, 'erp_product'));
    }

    /**
     * The controller, end to end, on the Doctrine store: a PUT writes the row, a GET reads it back, a
     * DELETE removes it. This kernel has no CSRF manager; the token check is covered by
     * PreferenceEndpointTest.
     */
    public function testTheEndpointRunsOnTheDoctrineStore(): void
    {
        $this->bootDoctrine();
        $ada = $this->account('ada@example.test');
        $tokenStorage = $this->container()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($ada, 'main', $ada->getRoles()));

        $put = $this->handle(Request::create('/datatable/preferences/erp_product', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: '{"columns":[{"key":"name","visible":false}]}'));
        self::assertSame(Response::HTTP_OK, $put->getStatusCode());
        self::assertCount(1, $this->rows());

        $get = $this->handle(Request::create('/datatable/preferences/erp_product'));
        self::assertSame(Response::HTTP_OK, $get->getStatusCode());
        self::assertStringContainsString('"key":"name"', (string) $get->getContent());

        $delete = $this->handle(Request::create('/datatable/preferences/erp_product', 'DELETE'));
        self::assertSame(Response::HTTP_NO_CONTENT, $delete->getStatusCode());
        self::assertSame([], $this->rows());
    }

    private function bootDoctrine(bool $withScope = false, bool $withStorage = true): ContainerInterface
    {
        $this->kernel = new DoctrinePreferenceKernel($withScope, $withStorage);
        $this->kernel->boot();

        if ($withStorage) {
            $entityManager = $this->entityManager();
            $schemaTool = new SchemaTool($entityManager);
            $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        }

        return $this->container();
    }

    private function store(bool $withScope = false): DatatablePreferenceStoreInterface
    {
        $this->bootDoctrine($withScope);
        $store = $this->container()->get(DatatablePreferenceStoreInterface::class);
        self::assertInstanceOf(DatatablePreferenceStoreInterface::class, $store);

        return $store;
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(DoctrinePreferenceKernel::class, $this->kernel);

        return $this->kernel->getContainer();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->container()->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function account(string $email, ?string $space = null): Account
    {
        $account = new Account($email)->setSpace($space);
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function moveTo(Account $account, string $space): void
    {
        $account->setSpace($space);
        $this->entityManager()->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->entityManager()->getConnection()->fetchAllAssociative('SELECT * FROM datatable_preference');
    }

    private function handle(Request $request): Response
    {
        self::assertInstanceOf(DoctrinePreferenceKernel::class, $this->kernel);

        return $this->kernel->handle($request);
    }
}
