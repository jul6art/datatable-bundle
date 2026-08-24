<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fous sur les feuilles de style du socle.
 *
 * Une règle CSS n'a pas de sortie observable sans navigateur ; la seule chose vérifiable est
 * qu'elle est encore écrite. C'est peu, et c'est ce qui a manqué chaque fois qu'un de ces réglages
 * a disparu au détour d'un remaniement pour ne se voir qu'en mode sombre, à l'écran.
 */
#[CoversNothing]
final class StylesheetTest extends TestCase
{
    /**
     * Select2 remplace le `<select>` par sa propre structure et peint son propre focus. Sans
     * `outline-none` sur `--focus`, on obtient le double trait bleu + blanc de Chrome par-dessus
     * l'anneau accent — le défaut est invisible en thème clair et criant en thème sombre.
     *
     * La classe visée est `--focus` et non `--open` : elles ont la même spécificité, donc la
     * seconde ne gagnerait pas sur la bordure slate de base au simple focus clavier.
     */
    public function testSelect2FocusKillsTheNativeOutline(): void
    {
        $css = self::read('select2.css');

        self::assertStringContainsString('select2-container--focus .select2-selection--single', $css);
        self::assertMatchesRegularExpression('/select2-container--focus[\s\S]*?outline-none[\s\S]*?ring-accent-500/', $css);
        self::assertStringContainsString(':focus-visible', $css);
    }

    /**
     * ⚠️ La densité de ligne n'est PAS ici, et c'est voulu : la règle
     * `[data-density='compact'] table.dataTable td` vit dans les jetons d'`admin-bundle`, avec le
     * réglage de compte qui la produit. Un projet qui prend ce socle sans la coquille n'a pas de
     * préférence de densité — lui livrer la règle ne ferait rien, et l'y chercher un jour ferait
     * perdre du temps. C'est la raison d'être de ce commentaire plutôt que d'une assertion.
     */

    /**
     * DataTables 2 a renommé toutes ses classes en `dt-*`. Une feuille qui viserait encore
     * `dataTables_*` s'appliquerait à rien, silencieusement — la table s'affiche, sans style.
     */
    public function testTheStylesheetTargetsTheV2ClassNames(): void
    {
        $css = self::read('datatable.css').self::read('datatable-custom.css');

        self::assertStringContainsString('dt-container', $css);
        self::assertStringContainsString('dt-paging', $css);
        self::assertStringNotContainsString('dataTables_wrapper', $css);
    }

    /**
     * Le sélecteur de colonnes et les vues enregistrées : deux panneaux qui n'existent qu'en
     * JavaScript, et dont rien ne dit qu'ils sont là sans navigateur. Ce test fige les deux
     * réglages qui les rendent utilisables — et qui sont invisibles à la lecture du code.
     */
    public function testThePreferencePanelsSitAboveTheFilterDropdowns(): void
    {
        $css = self::read('datatable.css');

        self::assertStringContainsString('.dt-prefs-panel', $css);
        // 120, pas `z-50` : les listes déroulantes Select2 de la ligne de filtres sont à 105 et se
        // portent sur le `<body>`. En dessous, le panneau s'ouvre DERRIÈRE le filtre qu'on vient
        // d'utiliser — défaut qu'on ne voit qu'en ouvrant les deux dans le bon ordre.
        self::assertMatchesRegularExpression('/\.dt-prefs-panel\s*\{[^}]*z-index:\s*120/', $css);
        // `relative` sur chaque `.dt-prefs` : deux panneaux, deux contextes de positionnement,
        // sinon ouvrir le second le décale de la largeur du premier.
        self::assertMatchesRegularExpression('/\.dt-prefs\s*\{[^}]*relative/', $css);
        // Le mode sombre est écrit ligne à ligne : un panneau sans variante `dark:` est un carré
        // blanc au milieu d'une page sombre, et c'est la moitié des captures d'écran.
        self::assertStringContainsString('dark:bg-slate-800', $css);
    }

    /**
     * Ce que le contrôleur Stimulus doit faire et que le CSS ne peut pas dire.
     *
     * Trois points ont chacun une raison d'exister qui ne se devine pas à la relecture, et un test
     * est ce qui empêche un remaniement de les faire disparaître silencieusement.
     */
    public function testThePreferencesAreReadBeforeTheTableIsBuilt(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        // Les préférences sont lues AVANT la construction : l'ordre et la visibilité entrent dans
        // les définitions de colonnes, donc les appliquer après voudrait dire construire la table
        // deux fois et tirer deux requêtes AJAX, la seconde remplaçant la première à l'écran.
        self::assertStringContainsString('await this._loadPreferences();', $js);
        // Une colonne masquée reste dans l'espace d'index de DataTables (`visible: false`), elle
        // n'est pas retirée de la liste : sinon `meta.col`, `sortField` et la ligne de filtres
        // devraient tous être recalculés à chaque coche.
        self::assertStringContainsString('visible: this._isColumnVisible(col.data)', $js);
        // L'ordre persisté est fait de CLÉS de colonne, pas d'index DataTables : un index ne veut
        // plus rien dire après un glisser-déposer, et un index périmé trie sur le mauvais champ
        // sans que rien ne le signale.
        self::assertStringContainsString('orderKeys: this._currentOrderKeys()', $js);
    }

    /**
     * Le piège trouvé au navigateur le 2026-08-24, et que rien d'autre ne rattrape.
     *
     * `connect()` tourne DEUX fois sur la même instance : construire la DataTable enveloppe le
     * `<table>` dans son conteneur, donc l'élément est réinséré et Stimulus déconnecte puis
     * reconnecte le contrôleur. Une affectation (`=`) au lieu d'un `??=` efface les préférences
     * tout juste adoptées — et une seule chose le montre, la ligne de filtres construite plus tard
     * depuis `initComplete`, décalée d'une cellule sous les mauvaises entêtes. Les colonnes, elles,
     * sont correctes : le défaut se lit comme un bug de filtres.
     */
    public function testTheAdoptedPreferencesSurviveTheSecondConnect(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('this._activeFilters ??= {};', $js);
        self::assertStringContainsString('this._columnPrefs ??= this._defaultColumnPrefs();', $js);
        self::assertStringContainsString('this._views ??= [];', $js);
        // Le piège s'est présenté DEUX fois, sur deux propriétés : la garde vaut pour les trois que
        // le boot remplit, et une affectation sèche sur l'une d'elles reviendrait au même défaut.
        self::assertStringNotContainsString('this._activeFilters = {};', $js);
    }

    /**
     * Un réordonnancement ne doit pas réécrire le tri.
     *
     * `_currentSort()` résout l'INDEX d'ordre vivant de DataTables à travers la liste de colonnes,
     * et le déplacement change ce que cet index désigne. Sans l'instantané pris avant, glisser une
     * colonne en position 2 persiste « trié par ce qui a atterri en position 2 » : le tableau
     * revient trié sur une colonne que personne n'a cliquée.
     */
    public function testAReorderDoesNotRewriteTheSort(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('await this._commitColumnOrder(sort);', $js);
        self::assertStringContainsString('_preferencePayload(sortOverride = null)', $js);
        // Détruire la table pour la reconstruire ne marche pas sur un élément piloté par Stimulus :
        // `destroy()` réinsère le `<table>`, le contrôleur se reconnecte et court-circuite sur
        // `data-datatable-initialized` pendant que l'instance qui a commandé la reconstruction
        // garde une table dont plus personne n'est propriétaire.
        self::assertStringNotContainsString('_rebuildTable', $js);
        self::assertStringContainsString('window.location.reload()', $js);
    }

    /**
     * Le panneau ne se ferme pas sur sa propre action.
     *
     * Enregistrer une vue, l'étoiler, la supprimer : chacun de ces clics fait re-rendre le panneau
     * pendant que l'événement remonte encore. L'écouteur « clic extérieur » posé sur `document` le
     * reçoit alors avec une cible DÉTACHÉE, `closest('.dt-prefs')` répond null, et le panneau se
     * ferme — sur l'action qu'on vient de faire dedans. Trouvé au navigateur le 2026-08-24.
     */
    public function testAPanelDoesNotCloseOnItsOwnAction(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('!target.isConnected', $js);
        self::assertStringContainsString('target.closest(\'.dt-prefs\')', $js);
    }

    /**
     * Pas de ligne de filtres quand aucune colonne VISIBLE n'en porte.
     *
     * Le tableau déclare des filtres, donc la garde d'entrée passe — mais l'utilisateur peut avoir
     * masqué toutes les colonnes qui en ont une. La construire quand même dessine une bande de
     * `<th>` vides sur toute la largeur : une seconde ligne d'entête, haute comme un champ de
     * filtre, qui ne filtre rien. Signalé le 2026-08-24.
     */
    public function testTheFilterRowIsNotDrawnWhenNoVisibleColumnCarriesAFilter(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString(
            'if (!allColumns.some(col => this.filtersValue.some(filter => filter.column === col.data))) {',
            $js,
        );
        // La garde vient AVANT la création du `<tr>` : la construire puis la jeter laisserait le
        // prochain remaniement la garder.
        self::assertLessThan(
            strpos($js, 'filterRow.className = \'dt-filter-row\';'),
            strpos($js, 'if (!allColumns.some(col => this.filtersValue.some('),
            'La garde doit précéder la création de la ligne.',
        );
    }

    /**
     * Les deux boutons appartiennent au même amas que la recherche globale.
     *
     * Ils sont insérés DANS la cellule de mise en page de la recherche, juste avant elle : ajoutés
     * à la ligne, ils atterrissaient après, désalignés (signalé le 2026-08-24). Le `flex-wrap` sur
     * cette cellule est ce qui les fait passer à la ligne sur mobile au lieu d'écraser le champ de
     * recherche.
     */
    public function testThePreferenceButtonsSitWithTheGlobalSearch(): void
    {
        $css = self::read('datatable.css');
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('search.parentElement.insertBefore(group, search);', $js);
        self::assertMatchesRegularExpression('/\.dt-layout-cell:has\(\.dt-prefs-group\)\s*\{[^}]*flex-wrap/', $css);
    }

    private static function read(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/'.$name);
    }

    /** Un asset non-CSS du bundle, chemin relatif à `assets/`. */
    private static function readAsset(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/'.$path);
    }

    /**
     * Le filtre de plage de dates tient sur UN champ.
     *
     * Deux `<input type="date">` empilés doublaient la hauteur de TOUTE la ligne de filtres —
     * pour une colonne qui, la plupart du temps, n'est pas filtrée (signalé le 2026-08-23). Le
     * champ unique ouvre un popover ; ce test fige la structure, faute de navigateur pour la
     * vérifier.
     */
    public function testTheDateRangeFilterIsASingleField(): void
    {
        $css = self::read('datatable.css');
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('.dt-filter-daterange__field', $css);
        self::assertStringContainsString('.dt-filter-daterange__popover', $css);
        // Sans cette règle, le `display: flex` du popover bat le `[hidden] { display: none }` du
        // navigateur et le popover reste ouvert en permanence, par-dessus la colonne voisine.
        self::assertStringContainsString('.dt-filter-daterange__popover[hidden]', $css);
        self::assertStringNotContainsString('__stack', $css, 'La pile verticale est ce qui a été remplacé.');

        self::assertStringContainsString('dt-filter-daterange__popover', $js);
        self::assertStringNotContainsString('stack.appendChild', $js);
        // Le contrat serveur ne bouge pas : les deux bornes DateFilter restent celles envoyées.
        self::assertStringContainsString("config.param + '[after]'", $js);
        self::assertStringContainsString("config.param + '[before]'", $js);
        // Un écouteur posé sur `document` par un filtre reconstruit à chaque redraw doit être
        // retiré, sinon ils s'accumulent silencieusement.
        self::assertStringContainsString('_dateRangeCleanups', $js);
    }

    /**
     * Le menu d'actions par ligne appelle `window._toggleDtDropdown` dans son markup inline. Le
     * bundle qui rend ce markup DOIT fournir la fonction : sans elle, le bouton ⋮ s'affiche et ne
     * fait rien — pas d'erreur, pas d'indice, une liste qui semble n'avoir aucune action
     * (signalé sur wovex le 2026-08-23 ; la fonction n'existait que dans le `app.js` de superp).
     */
    public function testTheActionsDropdownShipsItsBehaviour(): void
    {
        $controller = self::readAsset('controllers/datatable_controller.js');
        $service = self::readAsset('services/dropdown.js');

        self::assertStringContainsString('window._toggleDtDropdown(this)', $controller, 'Le markup appelle la fonction…');
        self::assertStringContainsString('installActionsDropdown()', $controller, '…et le contrôleur doit l\'installer.');
        self::assertStringContainsString('window._toggleDtDropdown =', $service);
        self::assertStringContainsString('_positionDtDropdown', $service);
    }
}
