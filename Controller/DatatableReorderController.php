<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Controller;

use Jul6Art\DatatableBundle\Reorder\RowReorderer;
use Jul6Art\DatatableBundle\Reorder\RowReorderRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Saves the order of a reorderable table in one call (2.9): `POST …/{key}/reorder` with
 * `{"ids": [3, 1, 2]}` — every row's identifier, in the new order.
 *
 * ONE route serves every reorderable table, like the preferences endpoint, and for the same
 * reason: the route is not declared for the application — where it sits decides the firewall
 * around it.
 *
 * ```yaml
 * # config/routes/datatable.yaml
 * datatable_reorder:
 *     resource: '@DatatableBundle/Controller/DatatableReorderController.php'
 *     type: attribute
 *     prefix: /admin/datatable
 * ```
 *
 * ## In this order
 *
 * 1. **The table**: a key no provider declares answers 404.
 * 2. **The permission** the provider named (`RowReorder::$permission`) — 403. Reordering writes
 *    every row; reading the list is not enough.
 * 3. **CSRF**, in `X-CSRF-Token` (a JSON body is not a form) — 403.
 * 4. **The set**: the identifiers must be exactly the rows, each once — otherwise the page went
 *    stale, and 409 tells the table to reload. Nothing is written.
 * 5. **One transaction**, through the ORM, so the application's listeners see the change.
 */
final readonly class DatatableReorderController
{
    public function __construct(
        private RowReorderRegistry $registry,
        private RowReorderer $reorderer,
        private AuthorizationCheckerInterface $authorizationChecker,
        // Null when `symfony/security-csrf` is not installed (a `suggest` of this bundle): the
        // partial could not have minted a token either. See the preferences controller.
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private string $csrfTokenId = 'datatable_reorder',
    ) {
    }

    #[Route(
        path: '/{key}/reorder',
        name: 'datatable_reorder',
        requirements: ['key' => '[a-z0-9][a-z0-9_.\-]{0,63}'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $key): JsonResponse
    {
        $table = $this->registry->find($key);

        if (null === $table) {
            return new JsonResponse(['error' => 'unknown_table'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->authorizationChecker->isGranted($table['reorder']->permission)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        if ($this->csrfTokenManager instanceof CsrfTokenManagerInterface
            && !$this->csrfTokenManager->isTokenValid(new CsrfToken($this->csrfTokenId, (string) $request->headers->get('X-CSRF-Token')))) {
            return new JsonResponse(['error' => 'invalid_csrf_token'], Response::HTTP_FORBIDDEN);
        }

        $ids = self::ids($request);

        if (null === $ids) {
            return new JsonResponse(['error' => 'malformed_payload'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->reorderer->reorder($table['entity'], $table['reorder']->field, $ids)) {
            return new JsonResponse(['error' => 'stale_order'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return list<int|string>|null
     */
    private static function ids(Request $request): ?array
    {
        try {
            $payload = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $ids = \is_array($payload) ? ($payload['ids'] ?? null) : null;

        if (!\is_array($ids) || !array_is_list($ids)) {
            return null;
        }

        foreach ($ids as $id) {
            if (!\is_int($id) && !\is_string($id)) {
                return null;
            }
        }

        /* @var list<int|string> $ids */
        return $ids;
    }
}
