<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Controller;

use Jul6Art\DatatableBundle\Preference\DatatablePreferenceInterpreter;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The three operations behind the column picker and the saved views: read them, replace them,
 * throw them away.
 *
 * ONE route serves every table in the application — the table's own key is the only path segment.
 * That is what makes the feature opt-in in one line of Twig instead of a controller per entity.
 *
 * ## Wiring
 *
 * The route is not declared for the application, on purpose: where it sits in the URL map is also
 * what decides the firewall around it.
 *
 * ```yaml
 * # config/routes/datatable.yaml
 * datatable_preferences:
 *     resource: '@DatatableBundle/Controller/DatatablePreferenceController.php'
 *     type: attribute
 *     prefix: /datatable/preferences
 * ```
 *
 * ```yaml
 * # config/packages/security.yaml
 * access_control:
 *     - { path: ^/datatable, roles: ROLE_USER }
 * ```
 *
 * ## Security
 *
 * - **The user comes from the token.** The client never sends an identifier, so writing another
 *   user's preferences is not "forbidden", it is unrepresentable — hence no ownership voter.
 * - **No permission code.** A column layout is a personal preference, not an authorisation: a user
 *   who can open the page can arrange it. What they may *see* is decided by the collection
 *   endpoint the table reads, which this never touches.
 * - **CSRF on the writes.** `PUT` and `DELETE` carry the token in `X-CSRF-Token`, because a JSON
 *   body is not a form and `DELETE` has no body at all. `SameSite=Lax` already blocks a
 *   cross-origin write on its own; the token is the second lock, and it is ten lines.
 */
final readonly class DatatablePreferenceController
{
    public function __construct(
        private DatatablePreferenceStoreInterface $store,
        private DatatablePreferenceInterpreter $interpreter,
        private TokenStorageInterface $tokenStorage,
        // Null when the application does not install `symfony/security-csrf`, which is a
        // `suggest` of this bundle. The check is then skipped rather than failing closed: the
        // partial that mints the token could not have rendered either, so there would be nothing
        // to compare against, and refusing every save would break the feature over a package the
        // application deliberately does not have.
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private string $csrfTokenId = 'datatable_preferences',
    ) {
    }

    /**
     * The key names a TABLE, not an entity: two screens listing the same entity with different
     * columns are two keys. The pattern is deliberately narrow — it becomes part of a storage key,
     * and a key with a slash in it would look like a path.
     */
    #[Route(
        path: '/{key}',
        name: 'datatable_preferences',
        requirements: ['key' => '[a-z0-9][a-z0-9_.\-]{0,63}'],
        methods: ['GET', 'PUT', 'DELETE'],
    )]
    public function __invoke(Request $request, string $key): JsonResponse
    {
        $user = $this->user();
        if (!$user instanceof UserInterface) {
            return new JsonResponse(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return match ($request->getMethod()) {
            Request::METHOD_GET => new JsonResponse($this->interpreter->decode($this->store->read($user, $key))),
            Request::METHOD_PUT => $this->replace($request, $user, $key),
            default => $this->reset($request, $user, $key),
        };
    }

    private function replace(Request $request, UserInterface $user, string $key): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'invalid_csrf_token'], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'malformed_payload'], Response::HTTP_BAD_REQUEST);
        }

        // The interpreted state is what gets stored AND what is answered — never the payload. The
        // client adopts the response, so a value that was bounded, deduplicated or dropped is
        // visible immediately instead of coming back changed on the next page load.
        $preferences = $this->interpreter->interpret($payload);
        $this->store->write($user, $key, $this->interpreter->encode($preferences));

        return new JsonResponse($preferences);
    }

    private function reset(Request $request, UserInterface $user, string $key): JsonResponse
    {
        if (!$this->isCsrfTokenValid($request)) {
            return new JsonResponse(['error' => 'invalid_csrf_token'], Response::HTTP_FORBIDDEN);
        }

        $this->store->delete($user, $key);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function isCsrfTokenValid(Request $request): bool
    {
        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(
            new CsrfToken($this->csrfTokenId, (string) $request->headers->get('X-CSRF-Token')),
        );
    }

    private function user(): ?UserInterface
    {
        return $this->tokenStorage->getToken()?->getUser();
    }
}
