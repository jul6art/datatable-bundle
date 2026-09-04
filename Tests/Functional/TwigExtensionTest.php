<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\Twig\DataTableCsrfExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Ce qui reste des extensions Twig après le passage au catalogue.
 *
 * ⚠️ `datatable_status_map()` et `datatable_bulk_translations()` ont disparu avec le partial
 * `_translations.html.twig` : elles TRANSPORTAIENT des libellés déjà traduits vers un attribut
 * HTML, et le navigateur a maintenant le catalogue. Ce que faisait leur configuration —
 * ÉNUMÉRER les cas d'un enum — survit dans
 * {@see \Jul6Art\DatatableBundle\Translation\DeclaredTranslationKeys}, qui le dit au garde de
 * traduction du projet au lieu de le poster dans une page.
 */
#[CoversNothing]
final class TwigExtensionTest extends AbstractFunctionalTestCase
{
    // ── CSRF / identifiant Stimulus ──────────────────────────────────────────

    public function testTheStimulusIdentifierAndTokenIdsAreConfigurable(): void
    {
        $csrf = $this->csrf([
            'stimulus_identifier' => 'core--datatable',
            'csrf' => ['single' => 'row_action', 'bulk' => 'many', 'preferences' => 'my_prefs'],
        ]);

        self::assertSame('core--datatable', $csrf->stimulusIdentifier());
        self::assertSame('row_action', $csrf->csrfTokenId('single'));
        self::assertSame('many', $csrf->csrfTokenId('bulk'));
        self::assertSame('my_prefs', $csrf->csrfTokenId('preferences'));
    }

    /**
     * The default the endpoint validates against. It is a surface of its own rather than a reuse of
     * `single`: what it guards is a personal preference, not a business state transition — and the
     * project rule is one token per surface.
     */
    public function testThePreferencesTokenHasItsOwnDefault(): void
    {
        self::assertSame('datatable_preferences', $this->csrf()->csrfTokenId('preferences'));
    }

    public function testAnUnknownTokenKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->csrf()->csrfTokenId('whatever');
    }

    // ── Les partials ─────────────────────────────────────────────────────────

    /**
     * public function testTheCsrfPartialMintsBothTokensUnderTheConfiguredPrefix(): void
     * {
     * $container = $this->boot(bundleConfig: ['stimulus_identifier' => 'tbl']);.
     *
     * // `csrf_token()` lit le jeton dans la session, et il n'y a pas de session hors requête.
     * // En pousser une est plus honnête que de remplacer le stockage : c'est ce qui se passe
     * // quand un vrai gabarit rend ce partial.
     * $requestStack = $container->get('request_stack');
     * self::assertInstanceOf(RequestStack::class, $requestStack);
     * $request = new Request();
     * $request->setSession(new Session(new MockArraySessionStorage()));
     * $requestStack->push($request);
     *
     * $twig = $container->get('twig');
     * self::assertInstanceOf(Environment::class, $twig);
     *
     * $rendered = $twig->render('@Datatable/datatable/_csrf.html.twig');
     *
     * self::assertStringContainsString('data-tbl-bulk-csrf-value="', $rendered);
     * self::assertStringContainsString('data-tbl-single-csrf-value="', $rendered);
     * }
     *
     * /**
     * The preferences partial, rendered through the same route import the documentation asks a
     * project to write. Two things it must produce and nothing else can: a URL that carries the
     * table's key, and the token the endpoint validates.
     *
     * The URL is the reason this is a functional test. `path()` needs the route to exist, so a
     * partial that named the route wrongly — or a project that forgot the import — fails loudly
     * here instead of rendering a panel that saves nothing.
     */
    public function testThePreferencesPartialCarriesTheTableKeyInItsUrl(): void
    {
        $container = $this->boot(bundleConfig: ['stimulus_identifier' => 'tbl'], withPreferences: true);

        $requestStack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = $twig->render('@Datatable/datatable/_preferences.html.twig', ['key' => 'erp_product']);

        self::assertStringContainsString('data-tbl-preferences-url-value="/datatable/preferences/erp_product"', $rendered);
        self::assertStringContainsString('data-tbl-preferences-csrf-value="', $rendered);
    }

    /**
     * The labels the two panels are built from. They live in the shared translations partial rather
     * than in the preferences one, because the Stimulus controller reads a single.
     * /**
     * Le domaine des clés `datatable.*` est une CONFIGURATION, pas un littéral.
     *
     * Coder `messages` en dur dans le partial force chaque consommateur à verser le catalogue du
     * tableau dans le domaine par défaut de l'application — ce qu'un projet qui répartit ses
     * catalogues par domaine fonctionnel traite comme une erreur bloquante (signalé sur `superp`
     * le 2026-08-24).
     */
    public function testTheTranslationDomainOfTheDatatableKeysIsConfigurable(): void
    {
        self::assertSame('messages', $this->csrf()->translationDomain(), 'Le défaut ne change pas : aucune rupture.');
        self::assertSame('datatable', $this->csrf(['translation_domain' => 'datatable'])->translationDomain());
    }

    /**
     * La colonne inter-tenants suit le domaine configuré sans le répéter : sa clé est une clé
     * `datatable.*` comme les autres.
     */
    public function testTheTenantColumnFollowsTheConfiguredDomain(): void
    {
        $container = $this->boot(bundleConfig: [
            'translation_domain' => 'nowhere',
            'tenant' => ['endpoint' => '/api/organizations'],
        ]);

        $admin = $container->get(AdminDataTableConfig::class);
        self::assertInstanceOf(AdminDataTableConfig::class, $admin);
        self::assertSame('datatable.col.organization', self::arr($admin->tenantFilter())['placeholder']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Réduit un `mixed` sorti d'un dictionnaire à un tableau, en échouant si ce n'en est pas un.
     *
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function csrf(array $bundleConfig = []): DataTableCsrfExtension
    {
        $service = $this->boot(bundleConfig: $bundleConfig)->get(DataTableCsrfExtension::class);
        self::assertInstanceOf(DataTableCsrfExtension::class, $service);

        return $service;
    }
}
