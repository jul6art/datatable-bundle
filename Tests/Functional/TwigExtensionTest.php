<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\Twig\DataTableBulkExtension;
use Jul6Art\DatatableBundle\Twig\DataTableCsrfExtension;
use Jul6Art\DatatableBundle\Twig\DataTableStatusMapExtension;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

#[CoversNothing]
final class TwigExtensionTest extends AbstractFunctionalTestCase
{
    // ── Status maps ──────────────────────────────────────────────────────────

    public function testAStatusMapIsNestedWhereTheRenderersReadIt(): void
    {
        $map = $this->statusMaps(['quote_status' => ['keys' => ['draft', 'sent']]])
            ->statusMap('quote_status');

        self::assertSame([
            'datatable' => [
                'quote_status' => [
                    // Sans catalogue, le traducteur rend la clé : c'est elle qu'on vérifie, donc
                    // le préfixe appliqué.
                    'draft' => 'datatable.quote_status.draft',
                    'sent' => 'datatable.quote_status.sent',
                ],
            ],
        ], $map);
    }

    public function testAMapCanBeNestedSomewhereElseWithItsOwnPrefix(): void
    {
        $map = $this->statusMaps(['country' => [
            'path' => ['organization', 'country'],
            'key_prefix' => 'organization.country.',
            'keys' => ['fr'],
        ]])->statusMap('country');

        self::assertSame(['organization' => ['country' => ['fr' => 'organization.country.fr']]], $map);
    }

    /**
     * Deux cartes demandées ensemble fusionnent en profondeur. Un `array_merge` plat écraserait la
     * première branche par la seconde — et la colonne perdrait ses libellés sans que rien ne le
     * signale.
     */
    public function testSeveralMapsAreDeepMerged(): void
    {
        $map = $this->statusMaps([
            'a' => ['keys' => ['x']],
            'b' => ['keys' => ['y']],
        ])->statusMap(['a', 'b']);

        self::assertSame(['a', 'b'], array_keys(self::arr($map['datatable'])));
    }

    /**
     * Un nom inconnu lève. Rendre un dictionnaire vide donnerait une colonne de badges sans
     * libellé, défaut qu'on ne découvre qu'en production et par un signalement d'utilisateur.
     */
    public function testAnUnknownMapThrowsRatherThanRenderingNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown datatable status map "nope"');

        $this->statusMaps(['a' => ['keys' => ['x']]])->statusMap('nope');
    }

    // ── Bulk translations ────────────────────────────────────────────────────

    /**
     * Le socle est toujours présent : c'est ce que tout tableau consomme, indépendamment des types
     * d'action déclarés.
     */
    public function testTheBaseBulkBlockIsAlwaysThere(): void
    {
        $datatable = self::arr($this->bulk()->getTranslations()['datatable']);

        self::assertArrayHasKey('bulk', $datatable);
        self::assertArrayHasKey('coalescence', $datatable);
        self::assertArrayHasKey('confirm', $datatable);
        self::assertArrayHasKey('subject_wrap', self::arr($datatable['modal']));
    }

    /**
     * Un type sans clés `modal.*` est **absent** de la carte, pas rendu en clé brute. Sans ce
     * filtre, un projet qui n'utilise pas les treize types par défaut verrait
     * « modal.unpublish.title » comme titre de modale.
     */
    public function testATypeWithoutTranslationsIsSkipped(): void
    {
        $modal = self::arr(self::arr($this->bulk()->getTranslations()['datatable'])['modal']);

        self::assertArrayNotHasKey('delete', $modal, 'Aucun catalogue chargé ici : donc aucun type ne doit passer.');
        self::assertSame([], $modal['bulk']);
    }

    /**
     * ⚠️ Le test qui compte : `bulk_actions` est un nœud prototype, donc ce que le projet déclare
     * REMPLACE la valeur par défaut au niveau de la configuration. La refusion se fait dans
     * l'extension du conteneur. Sans elle, déclarer un type ferait disparaître les treize autres —
     * sans erreur, sans trace.
     */
    public function testDeclaringATypeDoesNotEraseTheDefaults(): void
    {
        $extension = $this->bulk(['bulk_actions' => ['invite']]);

        $types = $this->declaredActionTypes($extension);

        self::assertContains('invite', $types);
        self::assertContains('delete', $types, 'Les types par défaut du bundle doivent survivre.');
        self::assertContains('restore', $types);
        self::assertSame(array_values(array_unique($types)), $types, 'Un type déclaré en double ne doit pas être compté deux fois.');
    }

    public function testMergeRecursiveKeepsBothBranches(): void
    {
        $merged = $this->bulk()->mergeRecursive(
            ['datatable' => ['status' => ['active' => 'A']]],
            ['datatable' => ['bulk' => ['apply' => 'B']]],
        );

        self::assertSame(['status' => ['active' => 'A'], 'bulk' => ['apply' => 'B']], self::arr($merged['datatable']));
    }

    public function testMergeRecursiveLetsTheRightHandSideWinOnAScalar(): void
    {
        $merged = $this->bulk()->mergeRecursive(['a' => ['b' => 'left']], ['a' => ['b' => 'right']]);

        self::assertSame('right', self::arr($merged['a'])['b']);
    }

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
     * Le partial est rendu pour de vrai. Une extension enregistrée ne prouve pas qu'un gabarit
     * l'atteint, et un partial n'a pas de sortie attendue tant que personne ne le rend.
     */
    public function testTheTranslationsPartialRendersAnAttributeCarryingNestedJson(): void
    {
        $container = $this->boot(bundleConfig: ['stimulus_identifier' => 'core--datatable']);
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = trim($twig->render('@Datatable/datatable/_translations.html.twig'));

        self::assertStringStartsWith('data-core--datatable-translations-value="', $rendered);

        $json = html_entity_decode((string) preg_replace('/^data-[^=]+="|"$/', '', $rendered), \ENT_QUOTES);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        // Imbriqué, pas plat : le mixin `translatable` descend l'arbre segment par segment, donc
        // une clé plate `datatable.status.active` ne serait jamais résolue.
        self::assertArrayHasKey('active', self::arr(self::arr($decoded['datatable'])['status']));
    }

    public function testTheCsrfPartialMintsBothTokensUnderTheConfiguredPrefix(): void
    {
        $container = $this->boot(bundleConfig: ['stimulus_identifier' => 'tbl']);

        // `csrf_token()` lit le jeton dans la session, et il n'y a pas de session hors requête.
        // En pousser une est plus honnête que de remplacer le stockage : c'est ce qui se passe
        // quand un vrai gabarit rend ce partial.
        $requestStack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = $twig->render('@Datatable/datatable/_csrf.html.twig');

        self::assertStringContainsString('data-tbl-bulk-csrf-value="', $rendered);
        self::assertStringContainsString('data-tbl-single-csrf-value="', $rendered);
    }

    /**
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
     * than in the preferences one, because the Stimulus controller reads a single
     * `translations-value` — a second attribute of the same name would overwrite the first, and the
     * table would come back with raw keys in its dropdowns.
     */
    public function testTheTranslationsPartialCarriesThePanelLabels(): void
    {
        $container = $this->boot();
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = trim($twig->render('@Datatable/datatable/_translations.html.twig'));
        $json = html_entity_decode((string) preg_replace('/^data-[^=]+="|"$/', '', $rendered), \ENT_QUOTES);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $datatable = self::arr($decoded['datatable']);

        self::assertSame(['button', 'hint', 'reset', 'close'], array_keys(self::arr($datatable['columns'])));
        self::assertSame(['button', 'hint', 'empty', 'name', 'save', 'default', 'delete'], array_keys(self::arr($datatable['views'])));
        self::assertArrayHasKey('saving', self::arr($datatable['error']));
    }

    /**
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
     * Et le partial le lit vraiment. Une clé cherchée dans le mauvais domaine rend la clé brute —
     * ce que ce test constate en demandant un domaine où rien n'est traduit.
     */
    public function testThePartialReadsTheConfiguredDomain(): void
    {
        $container = $this->boot(bundleConfig: ['translation_domain' => 'nowhere']);
        $twig = $container->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = trim($twig->render('@Datatable/datatable/_translations.html.twig'));
        $json = html_entity_decode((string) preg_replace('/^data-[^=]+="|"$/', '', $rendered), \ENT_QUOTES);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        // Pas de catalogue `nowhere` : le traducteur rend la clé, préfixe compris. C'est la preuve
        // que le domaine traverse bien jusqu'au `|trans`.
        self::assertSame('datatable.filters', self::arr($decoded['datatable'])['filters']);
    }

    /**
     * La carte de statuts et la colonne inter-tenants suivent le même domaine sans le répéter : ce
     * sont des clés `datatable.*` comme les autres, et les faire suivre à la main sur 46 cartes est
     * la façon sûre d'en oublier une.
     */
    public function testAStatusMapAndTheTenantColumnFollowTheConfiguredDomain(): void
    {
        $container = $this->boot(bundleConfig: [
            'translation_domain' => 'nowhere',
            'status_maps' => ['quote_status' => ['keys' => ['draft']]],
            'tenant' => ['endpoint' => '/api/organizations'],
        ]);

        $status = $container->get(DataTableStatusMapExtension::class);
        self::assertInstanceOf(DataTableStatusMapExtension::class, $status);
        $map = self::arr(self::arr(self::arr($status->statusMap(['quote_status']))['datatable'])['quote_status']);
        self::assertSame('datatable.quote_status.draft', $map['draft']);

        $admin = $container->get(AdminDataTableConfig::class);
        self::assertInstanceOf(AdminDataTableConfig::class, $admin);
        self::assertSame('datatable.col.organization', self::arr($admin->tenantFilter())['placeholder']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Réduit un `mixed` sorti d'un dictionnaire à un tableau, en échouant si ce n'en est pas un.
     * Sans ce passage, chaque descente dans l'arbre coûte deux lignes d'assertion.
     *
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * Reconstruit la liste des types telle que l'extension la reçoit : elle est privée, donc on la
     * lit sur la définition du conteneur — ce qui a l'avantage d'éprouver l'argument réellement
     * injecté, et pas ce qu'on croit avoir configuré.
     *
     * @return list<string>
     */
    private function declaredActionTypes(DataTableBulkExtension $extension): array
    {
        $property = new \ReflectionProperty($extension, 'actionTypes');

        /** @var list<string> $types */
        $types = $property->getValue($extension);

        return $types;
    }

    /**
     * @param array<string, array<string, mixed>> $maps
     */
    private function statusMaps(array $maps): DataTableStatusMapExtension
    {
        $service = $this->boot(bundleConfig: ['status_maps' => $maps])->get(DataTableStatusMapExtension::class);
        self::assertInstanceOf(DataTableStatusMapExtension::class, $service);

        return $service;
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function bulk(array $bundleConfig = []): DataTableBulkExtension
    {
        $service = $this->boot(bundleConfig: $bundleConfig)->get(DataTableBulkExtension::class);
        self::assertInstanceOf(DataTableBulkExtension::class, $service);

        return $service;
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
