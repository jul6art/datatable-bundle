<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

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
        $csrf = $this->csrf(['stimulus_identifier' => 'core--datatable', 'csrf' => ['single' => 'row_action', 'bulk' => 'many']]);

        self::assertSame('core--datatable', $csrf->stimulusIdentifier());
        self::assertSame('row_action', $csrf->csrfTokenId('single'));
        self::assertSame('many', $csrf->csrfTokenId('bulk'));
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
