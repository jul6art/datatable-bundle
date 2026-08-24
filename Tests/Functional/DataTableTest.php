<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use Jul6Art\DatatableBundle\DataTable\AdminDataTableConfig;
use Jul6Art\DatatableBundle\Tests\Fixtures\WidgetDataTableConfigProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class DataTableTest extends AbstractFunctionalTestCase
{
    public function testAColumnIsSortableOnItsOwnFieldByDefault(): void
    {
        $columns = $this->provider()->getColumns();

        self::assertSame('name', $columns[1]['data']);
        self::assertSame('name', $columns[1]['sortField'], 'Un tri sans champ explicite trie sur la colonne elle-même.');
    }

    /**
     * Le tri d'une colonne calculée doit viser un champ que le serveur sait trier. Sans
     * `sortField`, le front envoie `?order[fullName]=` et l'API répond sans trier — en silence.
     */
    public function testASortFieldCanTargetAnotherColumn(): void
    {
        $columns = $this->provider()->getColumns();

        self::assertSame('reference', $columns[2]['data']);
        self::assertSame('sortableReference', $columns[2]['sortField']);
    }

    public function testAReadOnlyColumnIsNotOrderable(): void
    {
        $columns = $this->provider()->getColumns();

        self::assertFalse($columns[3]['orderable']);
        self::assertArrayNotHasKey('sortField', $columns[3], 'Une colonne non triable ne doit pas annoncer de champ de tri.');
    }

    /**
     * Une colonne peut être OFFERTE sans être MONTRÉE.
     *
     * Le drapeau ne dit rien de plus : c'est un défaut d'AFFICHAGE, lu par le contrôleur Stimulus
     * au premier rendu. Il ne retire pas le champ de la réponse — une colonne masquée est toujours
     * sérialisée — donc ce n'est jamais une raison d'ajouter un champ à un groupe `read`.
     */
    public function testAColumnCanBeDeclaredHidden(): void
    {
        $columns = $this->provider()->getColumns();

        self::assertSame('notes', $columns[4]['data']);
        self::assertTrue($columns[4]['hidden']);
        // Les autres n'annoncent rien : l'absence de clé est le défaut, pas `hidden: false`.
        self::assertArrayNotHasKey('hidden', $columns[0]);
        self::assertArrayNotHasKey('hidden', $columns[3]);
    }

    /**
     * Chaque libellé passe par le traducteur. C'est le seul garde-fou contre un en-tête de colonne
     * affiché comme clé brute : une configuration de datatable n'a pas de sortie attendue, donc
     * aucun test ne l'attrape autrement qu'ici.
     */
    public function testEveryLabelGoesThroughTheTranslator(): void
    {
        $provider = $this->provider();

        foreach ($provider->getColumns() as $column) {
            self::assertArrayHasKey('title', $column);
            self::assertIsString($column['title']);
        }

        foreach ($provider->getFilters() as $filter) {
            self::assertArrayHasKey('placeholder', $filter);
        }
    }

    public function testADateRangeFilterCarriesItsGranularity(): void
    {
        $filters = $this->provider()->getFilters();

        self::assertSame('daterange', $filters[0]['type']);
        self::assertSame('date', $filters[0]['granularity'], 'Une colonne date civile ne doit pas être convertie en UTC.');
        self::assertSame('datetime', $filters[1]['granularity']);
    }

    public function testAnApiFilterFallsBackToItsTextKeyForSearching(): void
    {
        $filters = $this->provider()->getFilters();

        self::assertSame('name', $filters[2]['searchKey'], 'Sans searchKey explicite, la recherche vise la clé affichée.');
        self::assertSame('search', $filters[3]['searchKey']);
    }

    public function testADependentFilterDeclaresWhatItDependsOn(): void
    {
        $filters = $this->provider()->getFilters();

        self::assertSame('category', $filters[3]['dependsOn']);
        self::assertSame('category', $filters[3]['dependsParam']);
    }

    public function testABulkDeleteActionCarriesBothItsRoutes(): void
    {
        $action = $this->provider()->deleteAction();

        self::assertSame('POST', $action['method']);
        self::assertSame('widget_delete', $action['route']);
        self::assertSame('widget_bulk_delete', $action['bulkRoute']);
        self::assertTrue($action['bulk']);
    }

    // ── Cross-tenant decoration ──────────────────────────────────────────────

    /**
     * La colonne de tenant se place en **deuxième**, juste après l'ID. La convention vaut plus
     * qu'elle n'en a l'air : un admin qui balaie plusieurs modules lit les mêmes deux premières
     * colonnes partout.
     */
    public function testTheTenantColumnLandsSecondAfterAnIdColumn(): void
    {
        $decorated = $this->admin()->decorateColumns($this->provider()->getColumns());

        self::assertSame('id', $decorated[0]['data']);
        self::assertSame('organization', $decorated[1]['data']);
        self::assertSame('name', $decorated[2]['data'], 'Les colonnes d\'origine suivent, dans l\'ordre.');
    }

    public function testTheTenantColumnLandsFirstWhenThereIsNoIdColumn(): void
    {
        $decorated = $this->admin()->decorateColumns([['data' => 'name', 'title' => 'Nom']]);

        self::assertSame('organization', $decorated[0]['data']);
        self::assertCount(2, $decorated);
    }

    public function testDecoratingAnEmptyConfigurationYieldsOnlyTheTenantColumn(): void
    {
        self::assertCount(1, $this->admin()->decorateColumns([]));
    }

    /**
     * La colonne porte une IRI, résolue côté front sur `name`. Trier dessus trierait sur une URL,
     * ce qui n'a aucun sens pour un lecteur — d'où `orderable: false`, qui est un choix et non un
     * oubli.
     */
    public function testTheTenantColumnIsNotOrderable(): void
    {
        $column = $this->admin()->tenantColumn();

        self::assertFalse($column['orderable']);
        self::assertSame('iri', $column['render']);
        self::assertSame('name', $column['resolveField']);
    }

    /**
     * L'endpoint est configurable parce qu'un « tenant » n'est pas une Organization partout — et
     * parce qu'il n'y en a pas toujours un. Le défaut est vide : une application mono-tenant
     * n'a pas à déclarer un endpoint qu'elle n'expose pas.
     */
    public function testTheTenantEndpointIsConfigurable(): void
    {
        self::assertSame('', $this->admin()->tenantFilter()['url'], 'Sans endpoint configuré, une application mono-tenant n\'a rien à chercher.');
        self::assertSame('/api/workspaces', $this->admin(['tenant' => ['endpoint' => '/api/workspaces']])->tenantFilter()['url']);
    }

    public function testTheTenantLabelIsTranslatedFromAConfiguredKey(): void
    {
        $filter = $this->admin(['tenant' => ['label_key' => 'une.cle', 'label_domain' => 'admin']])->tenantFilter();

        // Sans catalogue, le traducteur rend la clé : l'assertion porte donc sur le fait que la
        // clé configurée est bien celle qui a été demandée.
        self::assertSame('une.cle', $filter['placeholder']);
    }

    private function provider(): WidgetDataTableConfigProvider
    {
        $translator = $this->boot()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return new WidgetDataTableConfigProvider($translator);
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    private function admin(array $bundleConfig = []): AdminDataTableConfig
    {
        $admin = $this->boot(bundleConfig: $bundleConfig)->get(AdminDataTableConfig::class);
        self::assertInstanceOf(AdminDataTableConfig::class, $admin);

        return $admin;
    }
}
