<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Aucun libellé destiné à un humain n'est écrit en dur dans le contrôleur Stimulus.
 *
 * ## Le défaut que ce test ferme
 *
 * ⚠️ Les deux `aria-label` des cases de sélection de masse étaient écrits **en français** dans le
 * JS : `aria-label="Tout sélectionner"` et `aria-label="Sélectionner"`. Un lecteur d'écran
 * anglophone, néerlandophone ou germanophone entendait donc du français, quelle que soit la langue
 * de la page — et sur les **trois** produits qui prennent ce socle.
 *
 * ⚠️ **Ce sont les seuls libellés du fichier qu'un utilisateur n'ENTEND que s'il ne peut pas voir
 * la case.** C'est ce qui explique l'oubli, et c'est ce qui en fait la gravité : personne ne le
 * remarque en relisant un écran.
 *
 * Constaté le 2026-09-03 en auditant le rendu de cegeta, dont l'interface est en anglais.
 *
 * ## Pourquoi un test qui lit le fichier
 *
 * Le socle n'a pas d'outillage de test JavaScript, et une chaîne en dur n'a pas de sortie
 * observable depuis PHP. Ce qui est vérifiable, c'est qu'elle n'est plus écrite — même approche
 * que {@see StylesheetTest}, et même valeur : peu, mais exactement ce qui a manqué.
 */
#[CoversNothing]
final class ControllerLocalisationTest extends TestCase
{
    /**
     * ⚠️ Un `aria-label` dont la valeur commence par une majuscule littérale est une chaîne écrite
     * à la main. Les valeurs légitimes passent par `${this.t(...)}` ou `${this._escHtml(...)}`,
     * donc commencent par `$`.
     */
    public function testNoAriaLabelIsWrittenByHand(): void
    {
        $js = self::readController();

        self::assertSame(
            [],
            self::allMatches('/aria-label="(?!\$)[^"$][^"]*"/', $js),
            'Un `aria-label` littéral est un libellé qui ne sera jamais traduit : il est lu tel '
            ."quel par un lecteur d'écran, dans la langue où il a été tapé. Passez par `this.t()`.",
        );
    }

    /**
     * La configuration `language` de DataTables vient du catalogue, pas du fichier.
     *
     * ⚠️ Ce test remplace celui qui gardait la paire des `aria-label` en vérifiant chaque moitié
     * DANS SON FICHIER — le JS lisait `bulk.select_all`, le partial envoyait
     * `datatable.bulk.select_all`, et rien ne comparait les deux. Le test restait vert pendant que
     * les cases de sélection de masse des trois back-offices annonçaient « bulk.select_all » à un
     * lecteur d'écran. La paire n'existe plus : la clé lue EST la clé du catalogue, et
     * {@see \Jul6Art\DatatableBundle\Tests\Unit\AssetTranslationKeysTest} la vérifie.
     *
     * ⚠️ Ce qu'il restait à garder, c'est `getLanguageConfig()` : onze phrases françaises et une
     * table `{ fr, en }` écrites DANS le JS, qui laissaient les trois autres langues de cegeta sur
     * un anglais à moitié rempli. Une table de langues dans un bundle n'a pas à savoir combien de
     * langues parle un produit — le serveur a le traducteur.
     *
     * ⚠️ Et le test vise CETTE méthode, pas « toute phrase en dur du fichier ». Un garde générique
     * a été essayé : il cherchait les littéraux accentués, et il est resté VERT quand on lui a
     * resservi `'Traitement en cours…'`. Un test dont la mutation passe donne une confiance qu'il
     * ne mérite pas — c'est exactement l'erreur que ce fichier existe pour ne plus refaire.
     */
    public function testTheDataTablesLanguageComesFromTheCatalogue(): void
    {
        $body = self::languageConfigBody();

        preg_match_all("/'([^']*)'/", $body, $literals);

        $hardcoded = array_values(array_filter(
            $literals[1],
            static fn (string $literal): bool => !str_starts_with($literal, 'datatable.dt.')
                && !str_starts_with($literal, '<i class='),
        ));

        self::assertSame([], $hardcoded, \sprintf(
            "getLanguageConfig() ne doit contenir que des clés `datatable.dt.*` et des icônes :\n  - %s",
            implode("\n  - ", $hardcoded),
        ));

        // ⚠️ Et les onze libellés doivent VRAIMENT y être : une méthode vidée passerait
        // l'assertion ci-dessus sans qu'aucun texte ne soit plus rendu.
        self::assertSame(11, substr_count($body, "this.t('datatable.dt."));
    }

    /**
     * @return list<string>
     */
    private static function allMatches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return $found[0];
    }

    /**
     * Le corps de `getLanguageConfig()`, accolades équilibrées.
     */
    private static function languageConfigBody(): string
    {
        $js = self::readController();
        $start = strpos($js, 'getLanguageConfig() {');

        self::assertIsInt($start, 'getLanguageConfig() a disparu ou changé de nom.');

        $depth = 0;
        $length = \strlen($js);

        for ($i = $start + \strlen('getLanguageConfig()'); $i < $length; ++$i) {
            $depth += '{' === $js[$i] ? 1 : ('}' === $js[$i] ? -1 : 0);

            if (0 === $depth && '}' === $js[$i]) {
                return substr($js, $start, $i - $start + 1);
            }
        }

        self::fail('Accolades déséquilibrées dans getLanguageConfig().');
    }

    private static function readController(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js');
    }
}
