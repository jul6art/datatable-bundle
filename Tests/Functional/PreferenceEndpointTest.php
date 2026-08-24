<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Jul6Art\DatatableBundle\Controller\DatatablePreferenceController;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceInterpreter;
use Jul6Art\DatatableBundle\Tests\Fixtures\InMemoryPreferenceStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The endpoint, driven through the kernel: a real route, a real user on the token, a real store.
 *
 * Unit-testing the controller would prove the `match` on the verb and nothing that has ever broken.
 * What breaks is the wiring — a route that never registers, a prefix the attribute loses, a
 * controller the resolver cannot find because a tag is missing. All of it only shows up here.
 */
#[CoversClass(DatatablePreferenceController::class)]
final class PreferenceEndpointTest extends AbstractFunctionalTestCase
{
    /**
     * One session for the whole test, attached to every request AND to the one the CSRF token is
     * minted from. That is the only way to exercise the real `CsrfTokenManager`: its storage is the
     * session, so a token generated against another session is a token the controller will refuse —
     * which is exactly the check being relied upon.
     */
    private ?Session $session = null;

    public function testAnAnonymousRequestIsRefused(): void
    {
        $this->boot(withPreferences: true);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->request('GET', 'erp_product')->getStatusCode());
    }

    /**
     * Nothing stored yet is the state of every user on every table until their first save. It must
     * answer the empty preferences, not a 404 the JavaScript would have to special-case.
     */
    public function testReadingWithNothingStoredAnswersEmptyPreferences(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $response = $this->request('GET', 'erp_product');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            new DatatablePreferenceInterpreter()->empty(),
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testWritingThenReadingGivesBackTheSanitisedState(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $written = $this->request('PUT', 'erp_product', [
            'columns' => [['key' => 'name'], ['key' => 'sku', 'visible' => false]],
            'sort' => ['key' => 'name', 'dir' => 'desc'],
            'views' => [['name' => 'Actifs', 'filters' => ['isActive' => 'true'], 'default' => true]],
        ]);

        self::assertSame(Response::HTTP_OK, $written->getStatusCode());

        /** @var array{columns: list<array{key: string, visible: bool}>, views: list<array{id: string}>} $payload */
        $payload = json_decode((string) $written->getContent(), true);
        self::assertSame(['name', 'sku'], array_column($payload['columns'], 'key'));
        self::assertSame([true, false], array_column($payload['columns'], 'visible'));
        self::assertSame('actifs', $payload['views'][0]['id']);

        // Read back through the store, not from the response: a store that never wrote would still
        // have answered correctly above.
        self::assertSame($payload, json_decode((string) $this->request('GET', 'erp_product')->getContent(), true));
    }

    /**
     * The answer is the interpreted state, never the payload. A client that adopted its own request
     * would show a name it did not actually store, until the next page load.
     */
    public function testTheResponseIsTheSanitisedStateAndNotAnEchoOfTheRequest(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $response = $this->request('PUT', 'erp_product', [
            'columns' => [['key' => 'sku'], ['key' => 'sku']],
            'views' => [['id' => 'forged', 'name' => 'Actifs'], ['name' => 'Actifs']],
        ]);

        /** @var array{columns: list<mixed>, views: list<array{id: string}>} $payload */
        $payload = json_decode((string) $response->getContent(), true);

        self::assertCount(1, $payload['columns'], 'Le doublon de colonne est effondré.');
        self::assertSame(['actifs', 'actifs-2'], array_column($payload['views'], 'id'), 'L\'id vient du nom, pas du payload.');
    }

    /**
     * A second `PUT` REPLACES: the endpoint is not a patch, the client sends the whole state. A
     * store that appended would grow a row per save and answer the first one for ever.
     */
    public function testASecondWriteReplacesTheFirst(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $this->request('PUT', 'erp_product', ['views' => [['name' => 'Actifs']]]);
        $this->request('PUT', 'erp_product', ['views' => [['name' => 'Inactifs']]]);

        /** @var array{views: list<array{name: string}>} $payload */
        $payload = json_decode((string) $this->request('GET', 'erp_product')->getContent(), true);

        self::assertSame(['Inactifs'], array_column($payload['views'], 'name'));
        self::assertCount(1, $this->store()->records, 'Un enregistrement par (utilisateur, tableau), pas un par sauvegarde.');
    }

    public function testDeletingResetsToTheApplicationDefaults(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();
        $this->request('PUT', 'erp_product', ['columns' => [['key' => 'sku']]]);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->request('DELETE', 'erp_product')->getStatusCode());
        self::assertSame([], $this->store()->records);
    }

    /** Deleting what is not there is not an error — the JavaScript resets a table it never saved. */
    public function testDeletingWhatWasNeverSavedIsNotAnError(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        self::assertSame(Response::HTTP_NO_CONTENT, $this->request('DELETE', 'erp_product')->getStatusCode());
    }

    /**
     * The user comes from the token and the client never sends an identifier, so writing another
     * user's preferences is not forbidden — it is unrepresentable. This is the test that says so.
     */
    public function testTwoUsersDoNotShareTheirPreferences(): void
    {
        $this->boot(withPreferences: true);

        $this->authenticate('alice@example.org');
        $this->request('PUT', 'erp_product', ['views' => [['name' => 'Alice']]]);

        $this->authenticate('bob@example.org');
        /** @var array{views: list<mixed>} $bob */
        $bob = json_decode((string) $this->request('GET', 'erp_product')->getContent(), true);
        self::assertSame([], $bob['views']);

        $this->request('PUT', 'erp_product', ['views' => [['name' => 'Bob']]]);

        $this->authenticate('alice@example.org');
        /** @var array{views: list<array{name: string}>} $alice */
        $alice = json_decode((string) $this->request('GET', 'erp_product')->getContent(), true);
        self::assertSame(['Alice'], array_column($alice['views'], 'name'));
    }

    /** The key names a TABLE: two screens listing the same entity keep their own layouts. */
    public function testTwoTablesOfTheSameUserAreIndependent(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $this->request('PUT', 'erp_product', ['views' => [['name' => 'Produits']]]);

        /** @var array{views: list<mixed>} $other */
        $other = json_decode((string) $this->request('GET', 'erp_supplier')->getContent(), true);

        self::assertSame([], $other['views']);
    }

    /**
     * The key becomes part of a storage key, so its shape is a route requirement rather than a
     * check inside the controller: a key with a slash in it would look like a path, and one with a
     * quote in it would reach the store.
     */
    public function testAKeyOutsideThePatternHasNoRoute(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->request('GET', 'erp/product')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->request('GET', 'Erp_Product')->getStatusCode());
    }

    /**
     * `SameSite=Lax` already blocks a cross-origin write on its own; the token is the second lock.
     * Both writes are guarded — a `DELETE` resets a layout just as destructively as a `PUT`.
     */
    public function testAWriteWithoutAValidTokenIsRefused(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        self::assertSame(Response::HTTP_FORBIDDEN, $this->request('PUT', 'erp_product', ['columns' => []], token: '')->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->request('PUT', 'erp_product', ['columns' => []], token: 'forged')->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->request('DELETE', 'erp_product', token: 'forged')->getStatusCode());
        self::assertSame([], $this->store()->records);
    }

    /** A read is not a write: it carries no token, and the browser sends none. */
    public function testAReadNeedsNoToken(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        self::assertSame(Response::HTTP_OK, $this->request('GET', 'erp_product', token: '')->getStatusCode());
    }

    public function testAMalformedPayloadIsRefusedWithoutTouchingTheStore(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        $request = Request::create(
            '/datatable/preferences/erp_product',
            Request::METHOD_PUT,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken()],
            content: '{"columns":',
        );
        $request->setSession($this->session());
        $response = $this->kernel()->handle($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame([], $this->store()->records);
    }

    /**
     * A route that only answers three verbs. `POST` on it is a client bug, and it must read as one
     * rather than as a save that silently did nothing.
     */
    public function testTheRouteAnswersOnlyItsThreeVerbs(): void
    {
        $this->boot(withPreferences: true);
        $this->authenticate();

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->request('POST', 'erp_product')->getStatusCode());
    }

    /**
     * Without a store the endpoint does not exist at all — the container must still compile, and
     * that is the whole point of `PreferenceControllerPass`.
     */
    public function testWithoutAStoreTheEndpointIsNotRegistered(): void
    {
        self::assertFalse($this->boot()->has(DatatablePreferenceController::class));
    }

    public function testWithAStoreTheEndpointIsRegistered(): void
    {
        self::assertTrue($this->boot(withPreferences: true)->has(DatatablePreferenceController::class));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param string|null               $token   null mints a valid one, a string is sent as is
     */
    private function request(string $method, string $key, ?array $payload = null, ?string $token = null): Response
    {
        $server = ['HTTP_X_CSRF_TOKEN' => $token ?? $this->csrfToken()];
        if (null !== $payload) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $request = Request::create(
            '/datatable/preferences/'.$key,
            $method,
            server: $server,
            content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
        );
        $request->setSession($this->session());

        return $this->kernel()->handle($request);
    }

    /**
     * A real token from the real manager. It has to be minted from inside a request that carries
     * the test's session, because the manager's storage IS the session — hence the seed request
     * pushed onto the stack and popped straight back off.
     */
    private function csrfToken(): string
    {
        $container = $this->kernel()->getContainer();

        $stack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $manager = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

        $seed = Request::create('/');
        $seed->setSession($this->session());
        $stack->push($seed);

        try {
            return $manager->getToken('datatable_preferences')->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function session(): Session
    {
        return $this->session ??= new Session(new MockArraySessionStorage());
    }

    private function authenticate(string $identifier = 'user@example.org'): UserInterface
    {
        $user = new InMemoryUser($identifier, null, ['ROLE_USER']);

        $tokenStorage = $this->kernel()->getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $user;
    }

    private function store(): InMemoryPreferenceStore
    {
        $store = $this->kernel()->getContainer()->get(InMemoryPreferenceStore::class);
        self::assertInstanceOf(InMemoryPreferenceStore::class, $store);

        return $store;
    }
}
