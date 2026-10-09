<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Unit\Controller;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\DatatableBundle\Controller\DatatableReorderController;
use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;
use Jul6Art\DatatableBundle\DataTable\RowReorder;
use Jul6Art\DatatableBundle\Reorder\RowReorderer;
use Jul6Art\DatatableBundle\Reorder\RowReorderRegistry;
use Jul6Art\DatatableBundle\Tests\Fixtures\Entity\Lot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Le point d'entrée du réordonnancement (2.9), dans son ordre : la table, la permission, le jeton,
 * le corps, l'ensemble — et seulement alors l'écriture.
 */
#[CoversClass(DatatableReorderController::class)]
#[CoversClass(RowReorderRegistry::class)]
final class DatatableReorderControllerTest extends TestCase
{
    private EntityManager $entityManager;

    /** @var list<int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2).'/Fixtures/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($this->entityManager)->createSchema([$this->entityManager->getClassMetadata(Lot::class)]);

        foreach (['a', 'b'] as $position => $name) {
            $lot = new Lot($name, $position);
            $this->entityManager->persist($lot);
            $this->entityManager->flush();
            $this->ids[] = (int) $lot->id;
        }
    }

    public function testTheNewOrderIsSaved(): void
    {
        $response = $this->controller()($this->request(['ids' => [$this->ids[1], $this->ids[0]]]), 'lots');

        self::assertSame(204, $response->getStatusCode());
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->find(Lot::class, $this->ids[1])?->position);
    }

    public function testAnUnknownTableIsNotFound(): void
    {
        self::assertSame(404, $this->controller()($this->request(['ids' => $this->ids]), 'nope')->getStatusCode());
    }

    public function testThePermissionTheProviderNamedIsAsked(): void
    {
        $checker = new class implements AuthorizationCheckerInterface {
            /** @var list<mixed> */
            public array $asked = [];

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                $this->asked[] = $attribute;

                return false;
            }
        };

        $response = $this->controller($checker)($this->request(['ids' => $this->ids]), 'lots');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['lot:reorder'], $checker->asked);
    }

    public function testAForgedTokenIsRefused(): void
    {
        self::assertSame(403, $this->controller()($this->request(['ids' => $this->ids], 'forged'), 'lots')->getStatusCode());
    }

    public function testAMalformedBodyIsABadRequest(): void
    {
        self::assertSame(400, $this->controller()($this->request(['ids' => ['a' => 1]]), 'lots')->getStatusCode());
        self::assertSame(400, $this->controller()($this->request(['order' => $this->ids]), 'lots')->getStatusCode());
    }

    public function testAStaleOrderIsAConflictAndWritesNothing(): void
    {
        $response = $this->controller()($this->request(['ids' => [$this->ids[1]]]), 'lots');

        self::assertSame(409, $response->getStatusCode());
        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->find(Lot::class, $this->ids[1])?->position);
    }

    private function controller(?AuthorizationCheckerInterface $checker = null): DatatableReorderController
    {
        $provider = new class(new IdentityTranslator()) extends AbstractDataTableConfigProvider {
            public function rootEntity(): string
            {
                return Lot::class;
            }

            public function rowReorder(): RowReorder
            {
                return new RowReorder('lots', 'position', 'lot:reorder');
            }

            public function getColumns(): array
            {
                return [];
            }
        };

        $tokens = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'good');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return 'datatable_reorder' === $token->getId() && 'good' === $token->getValue();
            }
        };

        $granted = new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return true;
            }
        };

        return new DatatableReorderController(new RowReorderRegistry([$provider]), new RowReorderer($this->entityManager), $checker ?? $granted, $tokens);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, string $token = 'good'): Request
    {
        return Request::create('/lots/reorder', 'POST', server: ['HTTP_X_CSRF_TOKEN' => $token], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
