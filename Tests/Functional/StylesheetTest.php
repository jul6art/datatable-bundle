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
}
