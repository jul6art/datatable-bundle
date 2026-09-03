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

    /** Les deux libellés de sélection de masse viennent bien du traducteur. */
    public function testTheBulkSelectionLabelsComeFromTheTranslator(): void
    {
        $js = self::readController();

        foreach (['bulk.select_all', 'bulk.select_row'] as $key) {
            self::assertStringContainsString(
                \sprintf("this.t('%s')", $key),
                $js,
                \sprintf('La case de sélection de masse doit lire « %s » via le traducteur.', $key),
            );
        }

        // ⚠️ Et la clé doit être ENVOYÉE, sinon `t()` retombe sur la clé elle-même et l'utilisateur
        // entend « bulk.select_all ». Les deux moitiés vont par paire.
        $twig = (string) file_get_contents(\dirname(__DIR__, 2).'/Resources/views/datatable/_translations.html.twig');

        foreach (['datatable.bulk.select_all', 'datatable.bulk.select_row'] as $key) {
            self::assertStringContainsString(
                \sprintf("'%s'|trans", $key),
                $twig,
                \sprintf('Le partial des traductions doit envoyer « %s » : sans elle, `t()` rend la clé brute.', $key),
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function allMatches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return $found[0];
    }

    private static function readController(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/datatable_controller.js');
    }
}
