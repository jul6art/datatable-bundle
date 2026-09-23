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
        // L'instantané voyage jusqu'à la reconstruction : c'est lui qui rouvre la table, et non
        // l'ordre vivant de DataTables, que le `splice` vient justement de faire mentir.
        self::assertStringContainsString('this._rebuildTable(sort)', $js);
    }

    /**
     * Un réordonnancement reconstruit la table SUR PLACE, il ne recharge plus la page.
     *
     * Le verdict du 2026-08-24 (« détruire et reconstruire ne marche pas sur un élément piloté par
     * Stimulus ») décrivait juste le symptôme — deux tables, deux lignes de filtres — mais pas la
     * cause : `destroy()` réinsère le `<table>`, Stimulus met en file un `disconnect()` puis un
     * `connect()`, et c'est ce `connect()` relançant `_boot()` en parallèle qui construisait la
     * seconde table. Un drapeau posé le temps du cycle suffit à le neutraliser.
     *
     * Le drapeau vit sur l'ÉLÉMENT, pas sur `this` : Stimulus est libre de confier la reconnexion à
     * une NOUVELLE instance de contrôleur, auquel cas une propriété d'instance ne garde rien — et
     * cette instance-là ressouscrirait Mercure par-dessus celle qui pilote encore la table, donc
     * chaque événement du flux ferait recharger la table deux fois.
     */
    public function testARebuildIsDrivenRatherThanReloaded(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        // Aucune ligne de CODE n'appelle le rechargement — le commentaire qui raconte pourquoi il a
        // disparu, lui, a sa place : c'est la seule trace du verdict qu'il remplace.
        self::assertDoesNotMatchRegularExpression('/^(?!\s*[*\/]).*window\.location\.reload\(\)/m', $js);
        self::assertStringContainsString("this.element.dataset.datatableRebuilding = '1'", $js);
        self::assertStringContainsString('get _isRebuilding()', $js);

        // La garde de `connect()` est AVANT le boot, sinon elle ne garde rien.
        self::assertMatchesRegularExpression('/connect\(\)\s*\{[\s\S]*?this\._isRebuilding[\s\S]*?this\._boot\(\)/', $js);
        // Celle de `disconnect()` empêche le démontage d'une table qui vient d'être remontée.
        self::assertMatchesRegularExpression('/disconnect\(\)\s*\{\s*(\/\/[^\n]*\n\s*)*if \(this\._isRebuilding\) return;/', $js);

        // Relâché depuis un macrotask : les rappels du MutationObserver de Stimulus sont des
        // microtasks, donc ils sont passés. Sans ce filet, un remaniement DOM qui se solde à zéro
        // laisserait le drapeau posé et avalerait le prochain vrai `connect()`.
        self::assertMatchesRegularExpression('/setTimeout\(\(\) => \{[\s\S]{0,160}?delete this\.element\.dataset\.datatableRebuilding/', $js);

        // `destroy()` n'emporte PAS la ligne de filtres : elle appartient au `<thead>` qu'il
        // restaure. La laisser fait sortir `_buildFilters()` par sa garde d'entrée, et la table
        // reconstruite garde une ligne dont les Select2 viennent d'être détruits — une bande de
        // cellules vides, aucun filtre, rien dans la console. Trouvé au navigateur le 2026-09-17.
        self::assertMatchesRegularExpression('/destroy\(\);[\s\S]{0,700}?this\.element\.querySelector\(\'\.dt-filter-row\'\)\?\.remove\(\);[\s\S]{0,200}?initializeDataTable\(\{ rebuild: true/', $js);
    }

    /**
     * Une reconstruction n'est pas une ouverture de page.
     *
     * `_openingFilters()` fait gagner la vue étoilée sur l'état de session — c'est la précédence
     * tranchée le 2026-08-24, et elle est juste à l'ouverture. La rejouer sur une reconstruction
     * ferait qu'un simple glisser de colonne reprendrait les filtres et le tri de la vue étoilée :
     * le geste changerait ce que la table MONTRE, pas seulement l'ordre de ses colonnes.
     */
    public function testARebuildKeepsWhatIsOnScreen(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertMatchesRegularExpression('/if \(!rebuild\) \{\s*this\._activeFilters = this\._openingFilters\(saved\);/', $js);
        self::assertStringContainsString('_resolveOrder(saved, preferred = null)', $js);
    }

    /**
     * Le numéro de page enregistré appartient à la REQUÊTE qui l'a produit.
     *
     * La session retient « page 5 » avec les filtres qui donnaient 120 lignes. En revenant sur
     * l'écran, une vue étoilée gagne sur ces filtres — c'est la précédence — et la table s'ouvrait
     * page 5 d'une requête qui en compte trois : écran vide, pied de table « de 101 à 3 sur 3 ».
     * Signalé le 2026-09-17, reproduit au navigateur.
     *
     * Comparé plutôt que marqué : cela couvre aussi la vue étoilée dont les filtres ont changé entre
     * deux visites, un `sessionStorage` écrit par une version antérieure, ou un second onglet qui a
     * déplacé la page sous celui-ci.
     */
    public function testTheStoredPageIsDroppedWhenTheOpeningQueryIsNotTheOneThatProducedIt(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('displayStart: (resetPage || !pageBelongsToThisQuery) ? 0 : (saved?.start || 0)', $js);
        self::assertMatchesRegularExpression(
            // The opening search term is part of "this query" too (since 2.5): a page handed
            // `initial-search-value` must not open on the page number another term produced.
            '/const pageBelongsToThisQuery = rebuild\s*\|\| \(openingSearch === \(saved\?\.search \|\| \'\'\)\s*&& JSON\.stringify\(this\._sortedFilters\(this\._activeFilters\)\) === JSON\.stringify\(this\._sortedFilters\(saved\?\.filters\)\)\)/',
            $js,
        );
        // Une reconstruction ne change pas la requête : elle garde sa page, sauf ordre contraire.
        self::assertStringContainsString('const pageBelongsToThisQuery = rebuild', $js);
    }

    /**
     * Une vue porte les colonnes qu'elle montre, dans l'ordre où elle les montre.
     *
     * Les clés VISIBLES seulement : l'ordre de ce qu'une vue masque n'a aucun effet observable, et
     * la forme complète `{key, visible}` coûterait quatre fois plus pour rien — vingt vues sur une
     * table large sortiraient des 16 Ko de `MAX_BYTES`, et `encode()` se mettrait à supprimer des
     * vues pour tenir, sans rien dire.
     */
    public function testASavedViewCarriesTheColumnsItShows(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('columns: this._visibleColumns.map(col => col.data)', $js);
        self::assertStringContainsString('_columnPrefsFromViewColumns(keys)', $js);
        // Une vue étoilée apporte ses colonnes AVANT la construction, donc sans reconstruction au
        // premier rendu — c'est le même moment que ses filtres et son tri.
        self::assertMatchesRegularExpression('/starredPrefs[\s\S]{0,200}?this\._columnPrefs = starredPrefs/', $js);
    }

    /**
     * Appliquer une vue ne reconstruit la table que si elle DÉPLACE une colonne.
     *
     * La visibilité a une API et se change sur place ; l'ordre n'en a pas. Comparer les clés, et non
     * leur nombre, est ce qui fait sortir « mêmes colonnes, autre ordre » comme un réordonnancement.
     */
    public function testApplyingAViewOnlyRebuildsWhenTheOrderChanges(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('this._rebuildTable(sort, { resetPage: true })', $js);
        self::assertStringContainsString('if (prefs) this._applyColumnVisibility();', $js);
        self::assertMatchesRegularExpression('/const reorders = prefs !== null[\s\S]{0,260}?JSON\.stringify\(prefs\.map\(pref => pref\.key\)\)/', $js);
    }

    /**
     * Toucher aux colonnes détache la vue, comme changer un filtre — décision du 2026-09-17.
     *
     * Rien n'est mis à `null` : l'appartenance se COMPARE, donc les colonnes entrent dans la
     * comparaison au même titre que les filtres. Et `_toggleColumn()` doit la recalculer lui-même,
     * parce que `visible()` ne change aucune requête : aucun dessin n'a lieu, et le bouton porterait
     * encore le nom d'une vue qui n'est plus ce que la table montre.
     */
    public function testChangingAColumnDetachesTheActiveView(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('const shown = JSON.stringify(this._visibleColumns.map(col => col.data));', $js);
        self::assertMatchesRegularExpression('/if \(!view\.columns\) return true;/', $js);
        self::assertMatchesRegularExpression('/_toggleColumn\(key\)[\s\S]*?this\._activeViewId = this\._matchingViewId\(\);\s*this\._syncViewButtonLabel\(\);/', $js);
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

    /**
     * Un panneau ancré à droite déborde par la gauche dès que la barre d'outils passe à la ligne.
     *
     * Le menu déroulant pend sous le bord DROIT de son bouton, donc il s'étend vers la gauche : juste
     * tant que le bouton est à droite de la barre, faux dès que la barre se replie et que les boutons
     * se retrouvent collés à gauche — un panneau de 16rem sort alors de l'écran (signalé le
     * 2026-08-24). Décidé par MESURE et non par media query : `.dt-layout-row` se replie selon la
     * largeur du CONTENU, donc il n'y a pas de point de rupture sur lequel s'accrocher.
     */
    public function testThePanelFlipsItsAnchorRatherThanLeavingTheTable(): void
    {
        $css = self::read('datatable.css');
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertMatchesRegularExpression('/\.dt-prefs-panel--start\s*\{[^}]*right-auto[^}]*left-0/', $css);
        // Un dernier filet : sur un écran plus étroit que le panneau, aucun ancrage ne suffit.
        self::assertMatchesRegularExpression('/\.dt-prefs-panel\s*\{[^}]*max-w-\[calc\(100vw-2rem\)\]/', $css);

        self::assertStringContainsString('_anchorPanel(panel)', $js);
        self::assertStringContainsString('panel.getBoundingClientRect().left < bounds.left', $js);
    }

    /**
     * Une colonne peut être OFFERTE sans être MONTRÉE.
     *
     * C'est ce qui rend une table large possible : depuis les préférences par utilisateur, une
     * colonne que le lecteur peut masquer ne coûte rien à celui qui n'en veut pas, donc la question
     * n'est plus « mérite-t-elle la largeur ? » mais « quelqu'un pourrait-il vouloir la voir ? ».
     *
     * Les deux points que ce test fige sont ceux dont l'oubli se paierait en production :
     * `hidden` n'est honoré que si la table a des préférences (sans sélecteur, une colonne masquée
     * serait inatteignable), et une colonne AJOUTÉE depuis la dernière sauvegarde reprend le défaut
     * déclaré — sinon livrer un lot de colonnes masquées élargirait la table de tous les
     * utilisateurs qui l'avaient déjà arrangée.
     */
    public function testAColumnCanBeOfferedWithoutBeingShown(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('visible: !(this._hasPreferences && col.hidden === true)', $js);
        self::assertStringContainsString('const declaredDefaults = new Map(this._defaultColumnPrefs()', $js);
        self::assertStringContainsString('declaredDefaults.get(col.data) !== false', $js);
    }

    /**
     * Cocher une colonne à rendu `iri` doit RÉSOUDRE ses IRI.
     *
     * `_resolvePageIris()` saute volontairement les colonnes masquées — un IRI qu'on n'affiche pas
     * n'est pas une requête à payer. Mais `column().visible(true)` réinsère les `<td>` du DERNIER
     * draw, donc les placeholders d'attente rendus pendant que la colonne était masquée, et rien
     * ne relançait la résolution : la colonne restait sur « … » indéfiniment, jusqu'à ce qu'un tri,
     * une pagination ou une recherche provoque un draw. C'est le défaut P1 du rapport
     * `2026-08-24` (mesuré sur `manager` de la table des salariés : zéro requête, cellules figées).
     *
     * Deux points sont figés ici, et l'oubli du second rendrait le premier inopérant : la bascule
     * demande la résolution, et cette demande redessine MÊME quand il n'y avait rien à récupérer —
     * une colonne dont les IRI sont déjà en cache porte quand même les placeholders du draw
     * précédent.
     */
    public function testShowingAnIriColumnResolvesIt(): void
    {
        $js = self::readAsset('controllers/datatable_controller.js');

        self::assertStringContainsString('this._resolvePageIris(true);', $js);
        self::assertStringContainsString('async _resolvePageIris(force = false)', $js);
        self::assertStringContainsString('if (iris.size === 0 && !force) return;', $js);
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
