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
